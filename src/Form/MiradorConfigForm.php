<?php

namespace Drupal\islandora_mirador\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\islandora_mirador\Annotation\IslandoraMiradorPlugin;
use Drupal\islandora_mirador\IslandoraMiradorPluginManager;
use Drupal\search_api\IndexInterface;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;
/**
 * Mirador Settings Form.
 */
class MiradorConfigForm extends ConfigFormBase {
  /**
   * @var \Drupal\islandora_mirador\IslandoraMiradorPluginManager
   */
  protected $miradorPluginManager;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'islandora_mirador.miradorconfig.form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $config = $this->config('islandora_mirador.settings');
    $form['mirador_library_fieldset'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Mirador library location'),
    ];
    $form['mirador_library_fieldset']['mirador_library_installation_type'] = [
      '#type' => 'radios',
      '#options' => [
        'local'=> $this->t('Local library placed in /libraries inside your webroot.'),
        'remote' => $this->t('Default remote location'),
      ],
      '#default_value' => $config->get('mirador_library_installation_type'),
    ];

    $plugins = [];
    foreach ($this->miradorPluginManager->getDefinitions() as $plugin_key => $plugin_definition) {
      $plugins[$plugin_key] = $plugin_definition['label'];
    }
    $form['mirador_library_fieldset']['mirador_enabled_plugins'] = [
      '#title' => $this->t('Enabled Plugins'),
      '#description' => $this->t('Which plugins to enable. The plugins must be compiled in to the application. See the documentation for instructions.'),
      '#type' => 'checkboxes',
      '#options' => $plugins,
      '#default_value' =>  $config->get('mirador_enabled_plugins'),
    ];
    $form['iiif_manifest_url_fieldset'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('IIIF Manifest URL'),
    ];
    $form['iiif_manifest_url_fieldset']['iiif_manifest_url'] = [
      '#type' => 'textfield',
      '#description' => $this->t('Absolute URL of the IIIF manifest to render.  You may use tokens to provide a pattern (e.g. "http://localhost/node/[node:nid]/manifest")'),
      '#default_value' => $config->get('iiif_manifest_url'),
      '#maxlength' => 256,
      '#size' => 64,
      '#required' => TRUE,
      '#element_validate' => ['token_element_validate'],
      '#token_types' => ['node'],
    ];
    $form['iiif_manifest_url_fieldset']['token_help'] = [
      '#theme' => 'token_tree_link',
      '#global_types' => FALSE,
      '#token_types' => ['node'],
    ];
    $index_options = [];
    foreach($this->getIndexes() as $index_id => $index) {
      $index_options[$index_id] = $index->label();
    }

    if(count($index_options) > 0) {

    }
    $form['solr_hocr_fieldset'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('OCR Highlighting'),
      ];
    $form['solr_hocr_fieldset']['info'] = [
      '#type' => 'item',
      '#markup' => t("Refer to the islandora_mirador documentation for how to set up text search highlighting in Mirador.")
    ];

    if (count($index_options)) {
      $form['solr_hocr_fieldset']['solr_hocr_paged_content_display'] = [
        '#validated' => TRUE,
        '#type' => 'select',
        '#title' => t('Paged Content IIIF Manifest view/display'),
        '#description' => t("Select the view/display being used to generate the IIIF manifest for repository items identified as \"Paged Content\" (having multiple \"Page\" objects as children)."),
        '#options' => $this->getIiifManifestViewsDisplayOptions('paged_content') ?? [],
        '#default_value' => $config->get('solr_hocr_paged_content_display'),
        '#empty_option' => t('-None-'),
        '#empty_value' => "",
      ];
      $form['solr_hocr_fieldset']['solr_hocr_page_display'] = [
        '#validated' => TRUE,
        '#type' => 'select',
        '#title' => t('Single Page IIIF Manifest view/display'),
        '#description' => t("Select the view/display being used to generate the IIIF manifest for repository items identified as a single \"Page\"."),
        '#options' => $this->getIiifManifestViewsDisplayOptions('page') ?? [],
        '#default_value' => $config->get('solr_hocr_page_display'),
        '#empty_option' => t('-None-'),
        '#empty_value' => "",
      ];
      $form['solr_hocr_fieldset']['solr_hocr_index'] = [
        '#type' => 'select',
        '#title' => t('Select the solr index that you are using to hold your ocr highlight content'),
        '#options' => $index_options,
        '#default_value' => $config->get('solr_hocr_index'),
        '#empty_option' => t('-None-'),
        '#empty_value' => "",
        '#ajax' => [
          'callback' => '::solrHocrFieldListCallback',
          'disable-refocus' => FALSE,
          'event' => 'change',
          'wrapper' => 'edit-solr_hocr_field',
          'progress' => [
            'type' => 'throbber',
            'message' => $this->t('Loading solr index field options...'),
          ],
        ],
      ];
      $form['solr_hocr_fieldset']['solr_hocr_field'] = [
        '#validated' => TRUE,
        '#type' => 'select',
        '#title' => t('Select the solr field that indexes your ocr highlight content'),
        '#options' => $config->get('solr_hocr_index') ? $this->hocrFieldOptionsFromIndexId($config->get('solr_hocr_index')) : [],
        '#default_value' => $config->get('solr_hocr_field'),
        '#empty_option' => t('-None-'),
        '#empty_value' => "",
        '#prefix' => '<div id="edit-solr_hocr_field">',
        '#suffix' => '</div>',
        '#states' => [
          'invisible' => [
            ':input[name="solr_hocr_index"]' => ['value' => ''],
          ],
        ],
      ];
    }
    else {
      $form['solr_hocr_fieldset']['no-index'] = [
        '#type' => 'item',
        '#markup' => '<div class="warning">' . t("No solr index found that contains a `Fulltext \"ocr_highlight\"` field.") . '</div>',
      ];
    }

    return $form;
  }

  public function solrHocrFieldListCallback(array &$form, FormStateInterface $form_state) {
    // Prepare our textfield. check if the example select field has a selected option.
    if ($index_id = $form_state->getValue('solr_hocr_index')) {
      $form['solr_hocr_fieldset']['solr_hocr_field']['#options'] = $this->hocrFieldOptionsFromIndexId($index_id);
      $form['solr_hocr_fieldset']['solr_hocr_field']['#default_value'] = $form_state->getValue('solr_hocr_field') ?? "";
    }
    else {
      $form['solr_hocr_fieldset']['solr_hocr_field']['#options'] = [];
    }
    // Return the updated select element.
    return $form['solr_hocr_fieldset']['solr_hocr_field'];
  }

  /**
   * Get list of search_api_solr indexes that...
   * 1. Index nodes (datasource id = entity:node)
   * 2. Include the text_ocr field type definition.
   *
   * @return \Drupal\search_api\IndexInterface[]|void
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\search_api\SearchApiException
   */
  private function getIndexes($index_id = NULL) {
    $datasource_id = 'entity:media';

    /** @var \Drupal\search_api\IndexInterface[] $indexes */
    $indexes = \Drupal::entityTypeManager()
      ->getStorage('search_api_index')
      ->loadMultiple(!empty($index_id) ? [$index_id] : NULL);

    foreach ($indexes as $index_id => $index) {
      $dependencies = $index->getServerInstance()->getDependencies();
      if (!$index->isValidDatasource($datasource_id)
        || empty($dependencies['config'])
        || !in_array('search_api_solr.solr_field_type.text_ocr_und_7_0_0', $dependencies['config'])
      ) {
        unset($indexes[$index_id]);
      }

      return $indexes;
    }
  }

  /**
   * Provide form options lists to select view and display that generate iiif manifests for
   * "Paged Content" and "Page" objects.
   *
   * @param  string  $manifestType
   *  'page' or 'paged_content'
   *
   * @return array
   *  An options list of identifiers constructed as `[view id]/[display id]`.
   */
  private function getIiifManifestViewsDisplayOptions(string $manifestType) {
    $options = [];
    $allViews = Views::getAllViews();
    /** @var Drupal\views\Entity\View $aView */
    foreach($allViews as $aView) {
      if($aView->get('base_table') == 'media_field_data') {
        $default_arguments = $aView->getDisplay('default')['display_options']['arguments'] ?? [];
        foreach($aView->get('display') as $displayId => $display) {
          if(!empty($display['display_options']['style']['type']) && $display['display_options']['style']['type'] == 'iiif_manifest') {
            $display = $aView->getDisplay($displayId);
            $arguments = $display['display_options']['arguments'] ?? $default_arguments;
            switch($manifestType) {
              case 'paged_content':
                if(!empty($arguments['field_member_of_target_id']) && $arguments['field_member_of_target_id']['relationship'] == 'field_media_of') {
                  $options[$aView->id() . "/" . $displayId] = $aView->label() . " (" . $aView->id() . ") / " . $display['display_title'] . " (". $displayId . ")";
                }
                break;
              case 'page':
                if(!empty($arguments['field_media_of_target_id']) && (empty($arguments['field_media_of_target_id']['relationship'] == 'none') || $arguments['field_media_of_target_id']['relationship'] == 'none')) {
                  $options[$aView->id() . "/" . $displayId] = $aView->label() . " (" . $aView->id() . ") / " . $display['display_title'] . " (". $displayId . ")";
                }
                break;
            }
          }
        }
      }
    }
    return $options;

  }


  /**
   * @param $index_id
   *
   * Get a list of the media fields that use the ocr_highlight solr field type.
   *
   * @return array
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\search_api\SearchApiException
   */
  private function hocrFieldOptionsFromIndexId($index_id) {
    $options = [];
    if(!empty($index_id)) {
      $search_api_index = $this->getIndexes($index_id)[$index_id] ?? NULL;
      if($search_api_index) {
        // Start by loading all the field type configs and getting a list of ocr_highlight field types.
        $configs = \Drupal::service('config.storage')->readMultiple(\Drupal::service('config.storage')->listAll('search_api_solr.solr_field_type'));
        foreach ($configs as $config) {
          if (!empty($config['custom_code']) && strpos($config['custom_code'], 'ocr_highlight') === 0) {
            // Here we end up with an array of search_api solr_text_custom fields and their corresponding language.
            $hocr_solr_field_languages['solr_text_custom:' . $config['custom_code']][] = $config['field_type_language_code'];
          }
        }
        $media_solr_fields = $search_api_index->getFieldsByDatasource('entity:media');
        foreach ($media_solr_fields as $field_id => $field_definition) {
          if (!empty($hocr_solr_field_languages[$field_definition->getType()])) {
            $options[$field_id] = $field_definition->getLabel();
          }
        }
      }
    }
    return $options;
  }


  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('islandora_mirador.settings');
    $config->set('mirador_library_installation_type', $form_state->getValue('mirador_library_installation_type'));
    $config->set('mirador_enabled_plugins', $form_state->getValue('mirador_enabled_plugins'));
    $config->set('iiif_manifest_url', $form_state->getValue('iiif_manifest_url'));
    $config->set('solr_hocr_paged_content_display', $form_state->getValue('solr_hocr_paged_content_display'));
    $config->set('solr_hocr_page_display', $form_state->getValue('solr_hocr_page_display'));
    $config->set('solr_hocr_index', $form_state->getValue('solr_hocr_index'));
    $config->set('solr_hocr_field', $form_state->getValue('solr_hocr_field'));
    $config->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'islandora_mirador.settings',
    ];
  }

  /**
   * Constructs the Mirador config form.
   *
   * @param ConfigFactoryInterface $config_factory
   * The configuration factory.
   * @param IslandoraMiradorPluginManager $mirador_plugin_manager
   * The Mirador Plugin Manager interface.
   */
  public function __construct(ConfigFactoryInterface $config_factory, IslandoraMiradorPluginManager $mirador_plugin_manager) {
    parent::__construct($config_factory);
    $this->miradorPluginManager = $mirador_plugin_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('plugin.manager.islandora_mirador')
    );
  }

}
