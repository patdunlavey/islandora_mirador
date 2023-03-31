<?php

namespace Drupal\islandora_mirador\Controller;

use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Config\ConfigFactoryInterface;
use \Drupal\Core\Config\ImmutableConfig;
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
use Solarium\Core\Query\QueryInterface as SolariumQueryInterface;
use Solarium\QueryType\Select\Query\Query as SolariumQuery;


/**
 * A Wrapper Controller to access Twig processed JSON on a URL.
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
   * @param  string  $page
   *
   * @return \Symfony\Component\HttpFoundation\Response
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\search_api\SearchApiException
   */
  public function search(Request $request, ContentEntityInterface $node, string $page = 'all') {

    $response = NULL;
    $child_nids = $this->getNodeChildren($node);
    // Set the array keys to serve as sequence (page) numbers.
    $child_nids = array_values($child_nids);
    $annotationsList = [];

    if (($input = $request->query->get('q')) && ($page == 'all')) {
      if(!empty($child_nids)) {
        foreach($child_nids as $sequence_id => $child_nid) {
          $child_node = \Drupal::entityTypeManager()->getStorage('node')->load($child_nid);
          if(!empty($child_node)) {
            $annotationsList[$sequence_id] = $this->getPageHocr($input, $child_node);
          }
        }
      }
      else {
        $annotationsList[] = $this->getPageHocr($input, $node);
      }

//      $response = new CacheableJsonResponse(
      $response = new JsonResponse(
        json_encode($annotationsList),
        200,
        ['content-type' => 'application/json'],
        TRUE
      );
    }
    elseif (is_numeric($page)) {
      $page = (int) $page;
      if(!empty($child_nids[$page] && $child_node = \Drupal::entityTypeManager()->getStorage('node')->load($child_nids[$page]))) {
        $annotationsList[$page] = $this->getPageHocr($input, $child_node);
      }
      else {
        $annotationsList[$page] = $this->getPageHocr($input, $node);
      }
//      $response = new CacheableJsonResponse(
      $response = new JsonResponse(
        json_encode($annotationsList),
        200,
        ['content-type' => 'application/json'],
        TRUE
      );
    }
    if ($response) {
      // Set CORS. IIIF and others will assume this is true.
      $response->headers->set('access-control-allow-origin', '*');
//      $response->addCacheableDependency($node);
//      if ($callback = $request->query->get('callback')) {
//        $response->setCallback($callback);
//      }
      return $response;
    }
    else {
      return new JsonResponse([]);
    }
  }


  /**
   * Gets ocr from solr for a node - directly from the node if it is a page, or from its children if it is paged content.
   *
   * @param  string  $term
   *  The term being searched for.
   * @param  ContentEntityInterface $node
   *  The node which has associated hocr content indexed.
   * @param  int  $limit
   *
   * @return array[]
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\search_api\SearchApiException
   */
  protected function getPageHocr(string $term, ContentEntityInterface $node, int $limit = 500) {
    $result_snippets = [];

    // If for any reason the solr index or hocr field are not present, just return the empty snippets array.
    if(!empty($this->solrIndex) && !empty($this->solrHocrField)) {
      $langcode = $node->language()->getId();
      $search_api_index = $this->solrIndex;
      $hocr_solr_field = $this->solrHocrField;

      // Initialize the query.
      $query = $search_api_index->query(['limit' => $limit, 'offset' => 0,]);
      $parse_mode = $this->parseModeManager->createInstance('terms');
      $query->setParseMode($parse_mode);
      $query->sort('search_api_relevance', 'DESC');
      $query->keys($term);
      $query->addCondition('search_api_id', "entity:node/" . $node->id() . ":" . $langcode);

      // Figure out the solr field that contains the hocr content. To do this we need language of this node, then find the corresponding solr field name.
      $solr_field_names = $this->solrIndex->getServerInstance()->getBackend()->getLanguageSpecificSolrFieldNames($langcode, $this->solrIndex);

      if (isset($solr_field_names[$hocr_solr_field])) {
        $query->setOption('search_api_bypass_access', TRUE);
        $query->setOption('search_api_retrieved_field_values', [$hocr_solr_field, 'nid', 'search_api_solr_score_debugging']);

        // TODO: This doesn't work. We can see the `hl.` parameters getting added in SearchApiSolrBackend::search, but they must be getting removed later.
        // Use `solr_param_` trick to inject solarium parameters: https://git.drupalcode.org/project/search_api_solr/-/blob/4.x/src/Plugin/search_api/backend/SearchApiSolrBackend.php#L1605-1610
        //        $query->setOption('solr_param_hl.ocr.fl', $solr_field_names[$hocr_solr_field]);
        //        $query->setOption('solr_param_hl.ocr.absoluteHighlights', 'on');
        //        $query->setOption('solr_param_hl.method', 'UnifiedHighlighter');
        // TODO: This is the alternate hack - add the highlight parameters via hook_search_api_solr_query_alter:
        // Set a flag with the solr field name that can be used in islandora_mirador_search_api_solr_query_alter to identify when to add the highlight parameters to the solarium query.
        $query->setOption('ocr_highlight', $solr_field_names[$hocr_solr_field]);

      }
      $fields_to_retrieve['nid'] = 'nid';
      $fields_to_retrieve['search_api_solr_score_debugging'] = 'search_api_solr_score_debugging';
      $fields_to_retrieve[$hocr_solr_field] = $hocr_solr_field;
      $query->setProcessingLevel(QueryInterface::PROCESSING_BASIC);
      $query->setOption('solr_param_df', 'nid');
      $results = $query->execute();
      $extradata = $results->getAllExtraData() ?? [];
      unset($fields_to_retrieve['nid']);
      // Just in case something goes wrong with the returning region text
      $region_text = $term;
      $page_number_by_id = [];
      if ($results->getResultCount() >= 1) {
        if (isset($extradata['search_api_solr_response']['ocrHighlighting']) && count(
            $extradata['search_api_solr_response']['ocrHighlighting']
          ) > 0) {
          foreach ($results as $result) {
            $extradata_from_item = $result->getAllExtraData() ?? [];
            //            print_r($extradata_from_item);
            //            if (isset($solr_field_names['parent_sequence_id']) &&
            //              isset($extradata_from_item['search_api_solr_document'][$solr_field_names['parent_sequence_id']])) {
            //              $sequence_number = (array) $extradata_from_item['search_api_solr_document'][$solr_field_names['parent_sequence_id']];
            //              if (isset($sequence_number[0]) && !empty($sequence_number[0]) && ($sequence_number[0] != 0)) {
            //                // We do all this checks to avoid adding a strange offset e.g a collection instead of a CWS
            //                $page_number_by_id[$extradata_from_item['search_api_solr_document']['id']] = $sequence_number[0];
            //              }
            //            }
            foreach ($fields_to_retrieve as $machine_name => $field) {
              $filedata_by_id[$extradata_from_item['search_api_solr_document']['id']][$machine_name] = $extradata_from_item['search_api_solr_document'][$field] ?? NULL;
            }
            // If we use getField we can access the RAW/original source without touching Solr
            // Not right now needed but will keep this around.
            //e.g $sequence_id = $result->getField('sequence_id')->getValues();
          }

//                    return $extradata['search_api_solr_response']['ocrHighlighting'];
          foreach ($extradata['search_api_solr_response']['ocrHighlighting'] as $sol_doc_id => $field) {
            $result_snippets_base = [];
            $previous_text = '';
            $accumulated_text = [];
            if (isset($field[$solr_field_names[$hocr_solr_field]]['snippets']) &&
              is_array($field[$solr_field_names[$hocr_solr_field]]['snippets'])) {
              foreach ($field[$solr_field_names[$hocr_solr_field]]['snippets'] as $snippet) {
                // IABR uses 0 to N-1. We may want to reprocess this for other endpoints.
                //$page_number = strpos($snippet['pages'][0]['id'], $page_prefix) === 0 ? (int) substr(
                //  $snippet['pages'][0]['id'],
                //  $page_prefix_len
                //) : (int) $snippet['pages'][0]['id'];
                if (isset($page_number_by_id[$sol_doc_id])) {
                  // If we have a Solr doc (means children) and their own page number use them here.
                  $page_number = $page_number_by_id[$sol_doc_id];
                }
                else {
                  // If not the case (e.g a PDF) go for it.
                  $page_number = preg_replace('/\D/', '', $snippet['pages'][0]['id']);
                }
                // Just some check in case something goes wrong and page number is 0 or negative?
                // and rebase page number starting with 0
                $page_number = ($page_number > 0) ? (int) ($page_number - 1) : 0;

                // We assume that if coords <1 (i.e. .123) => MINIOCR else ALTO
                // As ALTO are absolute to be compatible with current logic we have to transform to relative
                // To convert we need page width/height
                $page_width = (float) $snippet['pages'][0]['width'];
                $page_height = (float) $snippet['pages'][0]['height'];

                $result_snippets_base = [
                  'par' => [
                    [
                      'page' => $page_number,
                      'boxes' => $result_snippets_base['par'][0]['boxes'] ?? [],
                    ],
                  ],
                ];

                foreach ($snippet['highlights'] as $highlight) {

                  $region_text = str_replace(
                    ['<em>', '</em>'],
                    ['{{{', '}}}'],
                    $snippet['regions'][$highlight[0]['parentRegionIdx']]['text']
                  );

                  // check if coord >=1 (ALTO)
                  // else between 0 and <1 (MINIOCR)
                  if (((int) $highlight[0]['lrx']) > 0) {
                    //ALTO so coords need to be relative
                    $left = sprintf('%.3f', ((float) $highlight[0]['ulx'] / $page_width));
                    $top = sprintf('%.3f', ((float) $highlight[0]['uly'] / $page_height));
                    $right = sprintf('%.3f', ((float) $highlight[0]['lrx'] / $page_width));
                    $bottom = sprintf('%.3f', ((float) $highlight[0]['lry'] / $page_height));
                    $result_snippets_base['par'][0]['boxes'][] = [
                      'l' => $left,
                      't' => $top,
                      'r' => $right,
                      'b' => $bottom,
                      'page' => $page_number,
                    ];
                  }
                  else {
                    //MINIOCR coords already relative
                    $result_snippets_base['par'][0]['boxes'][] = [
                      'l' => $highlight[0]['ulx'],
                      't' => $highlight[0]['uly'],
                      'r' => $highlight[0]['lrx'],
                      'b' => $highlight[0]['lry'],
                      'page' => $page_number,
                    ];
                  }
                  $accumulated_text[] = $region_text;
                }
              }
              $result_snippets_base['text'] = !empty($accumulated_text) ? implode(" ... ", array_unique($accumulated_text)) : $term;
              // Add extra data that IAB does not need/nor understand but we do need
              // To match Image IDs against sequences/canvases on an arbitrary
              // IIIF manifest driven by a twig template.
              foreach ($fields_to_retrieve as $machine_name => $field) {
//                $result_snippets_base['sbf_metadata'][$machine_name] = $filedata_by_id[$sol_doc_id][$machine_name];
              }
            }
            $result_snippets[] = $result_snippets_base;
          }
        }
      }
    }
    return ['matches' => $result_snippets];
  }


  /**
   * Returns number of Ocr Documents for a given Node.
   *
   * @param  \Symfony\Component\HttpFoundation\Request  $request
   * @param  \Drupal\Core\Entity\ContentEntityInterface  $node
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function count(Request $request, ContentEntityInterface $node) {
    $count = 0;
    try {
      //      $count = $this->strawberryfieldUtility->getCountByProcessorInSolr(
      //      );
      return new JsonResponse(['count' => $count]);
    } catch (\Exception $exception) {
      // We do not want to throw nor record exceptions for public facing Endpoints
      return new JsonResponse(['count' => $count]);
    }
  }

  private function getNodeChildren(ContentEntityInterface $node) {
    $query = \Drupal::entityQuery('node');
    $query->condition('field_member_of', $node->id());
    $query->sort('field_weight', 'ASC');
    $query->sort('changed', 'ASC');
    return $query->execute();
  }

}
