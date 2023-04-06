<?php

namespace Drupal\islandora_mirador\Controller;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\search_api\Query\QueryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\search_api\ParseMode\ParseModePluginManager;

/**
 * Implements a IIIF Content Search API 1.0 Endpoint.
 *
 * This code, and the solr ocr highlighting configuration that it is based on,
 * is built from and gratefully indebted to the work of Diego Pino, Giancarlo Birello
 * and other contributors to the Archipelago Commons open source initiative of the
 * Metropolitan New York Library Council.
 *
 * @see https://github.com/esmero/strawberryfield/blob/1.1.0/src/Controller/StrawberryfieldFlavorDatasourceSearchController.php
 *
 * @see https://iiif.io/api/search/1.0/
 */
class OcrSearchController extends ControllerBase {

  /**
   * Symfony\Component\HttpFoundation\RequestStack definition.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $request;

  /**
   * The parse mode manager.
   *
   * @var \Drupal\search_api\ParseMode\ParseModePluginManager
   */
  protected $parseModeManager;

  /**
   * The islandora_mirador module settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * @var \Drupal\Core\Entity\EntityInterface|null
   */
  private $solrIndex;

  /**
   * @var array|mixed|null
   */
  private $solrHocrField;

  /**
   * OcrSearchController constructor.
   *
   * @param  \Symfony\Component\HttpFoundation\RequestStack  $request_stack
   *   The Symfony Request Stack.
   * @param  \Drupal\Core\Entity\EntityTypeManagerInterface  $entitytype_manager
   *   The Entity Type Manager.
   * @param  \Drupal\search_api\ParseMode\ParseModePluginManager  $parse_mode_manager
   *   The Search API parse Manager
   */
  public function __construct(
    RequestStack $request_stack,
    EntityTypeManagerInterface $entitytype_manager,
    ParseModePluginManager $parse_mode_manager
  ) {
    $this->request = $request_stack;
    $this->entityTypeManager = $entitytype_manager;
    $this->parseModeManager = $parse_mode_manager;
    $this->config = \Drupal::config('islandora_mirador.settings');
    $this->solrIndex = \Drupal::entityTypeManager()->getStorage('search_api_index')->load($this->config->get('solr_hocr_index'));
    $this->solrHocrField = $this->config->get('solr_hocr_field');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.search_api.parse_mode')
    );
  }


  /**
   * OCR Search Controller.
   *
   * @param  \Symfony\Component\HttpFoundation\Request  $request
   * @param  \Drupal\Core\Entity\ContentEntityInterface  $node
   * @param  string  $canvas
   *
   * @return \Symfony\Component\HttpFoundation\Response
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\search_api\SearchApiException
   */
  public function search(Request $request, ContentEntityInterface $node, string $canvas = 'all') {
    // Initialize an empty result, which we'll return if nothing else.
    $annotationsList = [];

    // If no "q" query parameter, we got no search.
    if ($input = $request->query->get('q')) {
      // $mids is media entity ids, aka canvas ids.
      $mids = [];
      // Determine the iiif manifest type: paged content, or page.
      $content_model = $node->get('field_model')->referencedEntities()[0]->label();
      if ($content_model == 'Paged Content') {
        [$view_id, $display_id] = explode('/', $this->config->get('solr_hocr_paged_content_display'));
      }
      else {
        // If not paged content, we assume page, but it could be any content model, as long as it has attached media.
        [$view_id, $display_id] = explode('/', $this->config->get('solr_hocr_page_display'));
      }
      if ($view_id && $display_id) {
        /** @var \Drupal\views\ViewExecutable $view */
        $view = \Drupal::entityTypeManager()->getStorage('view')->load($view_id)->getExecutable();
        $view->initDisplay();
        $view->setDisplay($display_id);
        $view->setArguments([$node->id()]);
        // TODO: Is there a way to just execute the query without executing the whole view?
        $view->execute();
        foreach ($view->result as $result) {
          if(!empty($result->mid)) {
            $mids[] = $result->mid;
          }
        }
      }

      if (is_numeric($canvas)) {
        $canvas = (int) $canvas;
        $mids = array_intersect([$canvas], $mids);
      }

      $annotations = $this->getCanvasHocr($input, $mids, $node);
      $annotationsList = [
        'startIndex' => 0,
        'within' => [
          'total' => count($annotations),
          '@type' => 'sc:Layer'
        ],
        '@type' => "sc:AnnotationList",
        'resources' => $annotations,
      ];

//        $response = new CacheableJsonResponse(
      $response = new JsonResponse(
        json_encode($annotationsList),
        200,
        ['content-type' => 'application/json'],
        TRUE
      );
    }

    if (!empty($response)) {
      // Set CORS. IIIF and others will assume this is true.
      $response->headers->set('access-control-allow-origin', '*');
      //      $response->addCacheableDependency($node);
      //      if ($callback = $request->query->get('callback')) {
      //        $response->setCallback($callback);
      //      }
      return $response;
    }

    // Nothing found, return an empty response.
    return new JsonResponse([]);
  }

  /**
   * Performs a solr ocrHighlighting search of media entities and returns a IIIF Presentation API 2.x
   * AnnotationList.
   *
   * @param  string  $term
   *  The text that is being searched within the page or paged content.
   * @param  array  $canvas_ids
   *  An array of media entity ids that correspond to the canvases being displayed in Mirador.
   * @param  \Drupal\Core\Entity\ContentEntityInterface  $node
   *  This is the node - either a page, or paged content - which is being displayed by Mirador.
   * @param  int  $limit
   *  Limit the number of canvases we search.
   *
   * @return array
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getCanvasHocr(string $term, array $canvas_ids, ContentEntityInterface $node, int $limit = 500) {
    $search_api_index = $this->solrIndex;
    $hocr_solr_field = $this->solrHocrField;

    // Initialize the search api query.
    $query = $search_api_index->query(['limit' => $limit, 'offset' => 0,]);
    $parse_mode = $this->parseModeManager->createInstance('terms');
    $query->setParseMode($parse_mode);
    $query->sort('search_api_relevance', 'DESC');
    $query->keys($term);

    // Add canvas id (media entity id) conditions to the search api query.
    $canvasConditions = $query->createConditionGroup('OR', ['content_access']);
    foreach($canvas_ids as $canvas_id_key => $canvas_id) {
      $media_entity = \Drupal::entityTypeManager()->getStorage('media')->load($canvas_id);
      if($media_entity) {
        $langcode = $media_entity->language()->getId();;
        $canvasConditions->addCondition('search_api_id', "entity:media/" . $canvas_id . ":" . $langcode);
      }
      else {
        // We got a bad media entity id. Remove it from the canvas ids.
        unset($canvas_ids[$canvas_id_key]);
      }
    }
    // Handle the possible case where the canvas id argument is bogus - just bail.
    if(count($canvas_ids) == 0) {
      return [];
    }
    $query->addConditionGroup($canvasConditions);

    $solr_field_names = $this->solrIndex->getServerInstance()->getBackend()->getLanguageSpecificSolrFieldNames($langcode, $this->solrIndex);
    $query->setOption('search_api_bypass_access', TRUE);
    if (isset($solr_field_names[$hocr_solr_field])) {
      $query->setOption('search_api_retrieved_field_values', [$hocr_solr_field, 'search_api_solr_score_debugging']);

      // TODO: This doesn't work. We can see the `hl.` parameters getting added in SearchApiSolrBackend::search, but they must be getting removed later.
      // Use `solr_param_` trick to inject solarium parameters: https://git.drupalcode.org/project/search_api_solr/-/blob/4.x/src/Plugin/search_api/backend/SearchApiSolrBackend.php#L1605-1610
      //        $query->setOption('solr_param_hl.ocr.fl', $solr_field_names[$hocr_solr_field]);
      //        $query->setOption('solr_param_hl.ocr.absoluteHighlights', 'on');
      //        $query->setOption('solr_param_hl.method', 'UnifiedHighlighter');
      // TODO: This is the alternate hack - add the highlight parameters via hook_search_api_solr_query_alter:
      // Set a flag with the solr field name that can be used in islandora_mirador_search_api_solr_query_alter to identify when to add the highlight parameters to the solarium query.
      $query->setOption('ocr_highlight', $solr_field_names[$hocr_solr_field]);

      $query->setProcessingLevel(QueryInterface::PROCESSING_BASIC);
      $query->setOption('solr_param_df', 'nid');
      $results = $query->execute();
      $extradata = $results->getAllExtraData() ?? [];

      if ($results->getResultCount() >= 1) {
        if (isset($extradata['search_api_solr_response']['ocrHighlighting']) && count(
            $extradata['search_api_solr_response']['ocrHighlighting']
          ) > 0) {

          // We have ocrHighlighting data. Now we're ready to build our annotation list.
          $annotations = [];
//          return $extradata['search_api_solr_response']['ocrHighlighting'];
          foreach ($extradata['search_api_solr_response']['ocrHighlighting'] as $sol_doc_id => $field) {
            $annotations = array_merge($annotations, $this->singleCanvasOcrToOpenAnnotation($field,  $sol_doc_id, $node->id() ));
          }
        }
      }
    }
    return $annotations;
  }


  /**
   * Starting with a solr ocrHighlight extra data array, return an array
   * of annotations for a given canvas (media entity).
   *
   * @param array $ocrResult
   *  solr ocrHighlighting data.
   * @param  string  $sol_doc_id
   *  search_api_solr record identifier.
   * @param int $node_id
   *
   *
   * @return array
   */
  private function singleCanvasOcrToOpenAnnotation(array $ocrResult, string $sol_doc_id, $node_id) {
    $annotations = [];
    $get_media_entity_id = function($doc_id) {
      preg_match("/^[a-z0-9\-_]+entity:media\/(?<nid>[0-9]+):[a-z]+$/", $doc_id, $matches);
      return $matches['nid'] ?? $doc_id;
    };
    $canvas_id = $get_media_entity_id($sol_doc_id);
    $base_url = $this->request->getCurrentRequest()->getSchemeAndHttpHost();
    foreach($ocrResult as $ocrResultFieldName => $ocrResultFieldResult) {
      if(!empty($ocrResultFieldResult['snippets'])) {
        foreach($ocrResultFieldResult['snippets'] as $snippetId => $snippet) {
          if(!empty($snippet['pages']) && !empty($snippet['regions']) && !empty($snippet['highlights'])) {
            foreach($snippet['highlights'] as $highlightIdx => $highlight) {
              foreach($highlight as $highlightPart) {

                $xywh = [
                  $highlightPart['ulx'],
                  $highlightPart['uly'],
                  $highlightPart['lrx'] -  $highlightPart['ulx'],
                  $highlightPart['lry'] -  $highlightPart['uly'],
                ];

                $json = [
                  '@type' => "oa:Annotation",
                  "motivation" => "sc:painting",
                  "resource" => [
                    "@type" => "dctypes:Text",
                    "format" => "text/html",
                    "chars" => "<p>" . $highlightPart['text'] . "</p>",
                    "http://dev.llgc.org.uk/sas/full_text" => $highlightPart['text']

                  ],
                  // "on" consists of [http scheme]/[base url]/node/[parent object ID if displaying paged content; child object ID if displaying page]/canvas/[media entity ID]#xywh[highlight xy position (upper left) and width and height]
                  "on" => $base_url . '/node/' . $node_id  . '/canvas/' . $canvas_id . "#xywh=" . implode(",", $xywh),
                  // "@id" is a persistent unique identifier for this particular annotation.
                  "@id" => $base_url . '/annotation' . md5('/node/' . $node_id  . '/canvas/' . $canvas_id . "#xywh=" . implode(",", $xywh)),
                  // TODO: What is this @context - where should I get it from?
                  "@context" => "file:/usr/local/tomcat/webapps/ROOT/contexts/iiif-2.0.json",
                  "label" => $highlightPart['text'],
                ];
                $annotations[] = $json;
              }
            }
          }
        }
      }
    }

    return $annotations;
  }

}
