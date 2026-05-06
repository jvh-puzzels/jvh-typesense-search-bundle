<?php
/**
 * Copyright (C) 2026  Jaap Jansma (jaap.jansma@civicoop.org)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace JvH\JvHTypesenseSearchBundle\EventListener;

use Contao\CoreBundle\Search\Document;
use Contao\Database;
use Contao\Environment;
use Contao\FilesModel;
use Contao\StringUtil;
use JvH\JvHPuzzelDbBundle\Model\PuzzelPlaatModel;
use Krabo\TypesenseSearchBundle\Event\TypesenseFECollectionEvent;
use Krabo\TypesenseSearchBundle\Event\TypesenseIndexEvent;
use Krabo\TypesenseSearchBundle\Event\TypesensePostSchemaEvent;
use Krabo\TypesenseSearchBundle\Event\TypesenseSchemaEvent;
use Krabo\TypesenseSearchBundle\Typesense;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class TypesenseListener implements EventSubscriberInterface {

  protected RequestStack $requestStack;

  public function __construct(RequestStack $requestStack) {
    $this->requestStack = $requestStack;
  }

  /**
   * Returns an array of event names this subscriber wants to listen to.
   *
   * The array keys are event names and the value can be:
   *
   *  * The method name to call (priority defaults to 0)
   *  * An array composed of the method name to call and the priority
   *  * An array of arrays composed of the method names to call and respective
   *    priorities, or 0 if unset
   *
   * For instance:
   *
   *  * ['eventName' => 'methodName']
   *  * ['eventName' => ['methodName', $priority]]
   *  * ['eventName' => [['methodName1', $priority], ['methodName2']]]
   *
   * The code must not depend on runtime state as it will only be called at
   * compile time. All logic depending on runtime state must be put into the
   * individual methods handling the events.
   *
   * @return array<string, mixed> The event names to listen to
   */
  public static function getSubscribedEvents() {
    return [
      TypesenseIndexEvent::class => 'onTypesenseIndex',
      TypesenseSchemaEvent::class => 'onTypesenseSchema',
      TypesensePostSchemaEvent::class => 'onTypesensePostSchema',
      TypesenseFECollectionEvent::class => 'onTypesenseFECollection',
    ];
  }

  public function onTypesenseFECollection(TypesenseFECollectionEvent $event) {
    $analytics_tags = [];
    $request = $this->requestStack->getMainRequest();
    $ip = $request->getClientIp();
    if (!empty($GLOBALS['TL_CONFIG']['jvh_typesense_backoffice_ip'])) {
      $ips = explode(" ", $GLOBALS['TL_CONFIG']['jvh_typesense_backoffice_ip']);
      if (in_array($ip, $ips)) {
        $analytics_tags[] = 'Backoffice';
      }
    }
    $analytics_tags[] = 'Module (' . $event->module->id . ')';
    if (isset($GLOBALS['TL_LANG']['FMD'][$event->module->type])) {
      $analytics_tags[] = $GLOBALS['TL_LANG']['FMD'][$event->module->type][0];
    }
    $analytics_tags[] = $event->collection['label'];
    $event->collection['analytics_tag'] = '"' . implode(" ", $analytics_tags) . '"';
  }

  public function onTypesenseIndex(TypesenseIndexEvent $event) {
    if ($event->type == 'page') {
      $baseUrl = Environment::get('base');

      if (!empty($event->sourceData['rootPageId'])) {
        $objRootPage = \PageModel::findByPk($event->sourceData['rootPageId']);
        if ($objRootPage) {
          $baseUrl = ($objRootPage->useSSL ? 'https://' : 'http://' ) . $objRootPage->dns . '/';
        }
      }

      /** @var Document $document */
      $document = $event->sourceData['document'];
      $event->document['image_url'] = '';
      $event->document['puzzel_platen'] = [];
      $page = \PageModel::findByPk($event->sourceData['pageId']);
      if ($page && $page->searchImage) {
        $image = \FilesModel::findByUuid($page->searchImage);
        if ($image) {
          $event->document['image_url'] = $baseUrl . $image->path;
        }
      }
      $jsonLdScriptsData =  $document->extractJsonLdScripts('https://json-ld.org/contexts/person.jsonld');
      foreach ($jsonLdScriptsData as $jsonLdScript) {
        if (!empty($jsonLdScript['name'])) {
          $objPuzzelPlaten = Database::getInstance()->prepare("SELECT tl_jvh_db_puzzel_plaat.* FROM tl_jvh_db_puzzel_plaat INNER JOIN tl_jvh_db_tekenaar ON tl_jvh_db_puzzel_plaat.tekenaar = tl_jvh_db_tekenaar.id  WHERE CONCAT(tl_jvh_db_tekenaar.voornaam, ' ', tl_jvh_db_tekenaar.achternaam) = ?")->execute($jsonLdScript['name']);
          while ($objPuzzelPlaat = $objPuzzelPlaten->next()) {
            $event->document['puzzel_platen'][] = StringUtil::decodeEntities($objPuzzelPlaat->naam_nl);
            $event->document['puzzel_platen'][] = StringUtil::decodeEntities($objPuzzelPlaat->naam_en);
          }
          $event->document['puzzel_platen'] = array_values(array_unique($event->document['puzzel_platen']));
        }
      }
      $jsonLdScriptsData =  $document->extractJsonLdScripts('https://schema.jvh-puzzels.nl/puzzelplaat');
      foreach ($jsonLdScriptsData as $jsonLdScript) {
        if (!empty($jsonLdScript['id'])) {
          $puzzelPlaat = PuzzelPlaatModel::findByPk($jsonLdScript['id']);
          if ($puzzelPlaat && !empty($puzzelPlaat->singleSRC)) {
            $image = FilesModel::findById($puzzelPlaat->singleSRC);
            if ($image) {
              $event->document['image_url'] = $baseUrl . $image->path;
            }
          }
        }
      }
    }
  }

  public function onTypesenseSchema(TypesenseSchemaEvent $event) {
    if ($event->type == 'page') {
      $event->schema['fields'][] = [
        'name' => 'image_url',
        'type' => 'string',
        'index' => false,
        'optional' => true,
      ];
      $event->schema['fields'][] = [
        'name' => 'puzzel_platen',
        'type' => 'string[]',
        'index' => true,
        'optional' => true,
      ];
    }
  }

  public function onTypesensePostSchema(TypesensePostSchemaEvent $event) {
    $collection = $event->schema['name'];
    $this->createAnalyticsRule($collection, 'popular_queries', $event->typesense);
    $this->createAnalyticsRule($collection, 'nohits_queries', $event->typesense);
  }

  protected function createAnalyticsRule(string $collection, string $type, Typesense $client): void {
    $destinationCollection = $type;
    if (!$client->doesCollectionExist($destinationCollection)) {
      $schema['name'] = $client->getPrefix() . $destinationCollection;
      $schema['fields'] = [
        [
          'name' => 'q',
          'type' => 'string',
        ],
        [
          'name' => 'count',
          'type' => 'int32',
        ],
        [
          'name' => 'analytics_tag',
          'type' => 'string',
          'optional' => true,
          'facet' => true,
        ],
      ];
      try {
        $client->getClient()->collections->create($schema);
      } catch (\Exception $e) {
        // Do nothing
      }
    }

    $rule = [
      'name' => $collection . '_' . $type,
      'type' => $type,
      'collection' => $collection,
      'event_type' => 'search',
      'ruletag' => $collection,
      'params' => [
        'meta_fields' => ['analytics_tag'],
        'destination_collection' => $client->getPrefix() . $destinationCollection,
        'limit' => 1000,
        'capture_search_requests' => true,
      ],
    ];
    if (!$this->doesRuleExist($rule['name'], $client)) {
      $client->getClient()->analytics->rules()->create([$rule]);
    }
  }

  public function doesRuleExist(string $ruleName, Typesense $client): bool {
    $rules = $client->getClient()->analytics->rules()->retrieve();
    foreach ($rules as $rule) {
      if ($rule['name'] === $ruleName) {
        return true;
      }
    }
    return false;
  }


}