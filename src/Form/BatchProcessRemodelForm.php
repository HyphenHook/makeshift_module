<?php

namespace Drupal\makeshift_module\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\paragraphs\Entity\Paragraph;

class BatchProcessRemodelForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'batch_process_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Run Batch Remodel Process'),
      '#submit' => ['::submitForm'],
    ];

    $form['another_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Clear Old Content Type'),
      '#submit' => ['::anotherSubmit'],
    ];

    return $form;
  }

  public function anotherSubmit(array &$form, FormStateInterface $form_state) {
    $content_type = \Drupal::entityTypeManager()->getStorage('node_type')->load('annotation_collection');

    // Check if the content type exists before trying to delete it
    if ($content_type) {
      $content_type->delete();
    }

    $content_type2 = \Drupal::entityTypeManager()->getStorage('node_type')->load('annotation_textualbody');
    if ($content_type2) {
      $content_type2->delete();
    }

    // Array of field machine names to delete
    $fields_to_delete = ['field_annotation_image_source', 'field_annotation_image_value', 'field_annotation_page', 'field_annotation_target_element', 'field_annotation_target_end',
    'field_annotation_target_exact', 'field_annotation_target_start', 'field_annotation_textualbodies']; 

    foreach ($fields_to_delete as $field_name) {
      // Load the field config entity
      $field = \Drupal::entityTypeManager()->getStorage('field_config')->load('node.annotation.' . $field_name);

      // Check if the field exists before trying to delete it
      if ($field) {
        $field->delete();
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $nids = \Drupal::entityQuery('node')
    ->condition('type', 'annotation_collection')
    ->accessCheck(FALSE)
    ->execute();

    $chunks = array_chunk($nids, 2);
    $operations = [];

    foreach ($chunks as $chunk) {
      $operations[] = [
        '\Drupal\makeshift_module\Form\BatchProcessRemodelForm::updateNodeField',
        [$chunk],
      ];
    }
    $batch = [
      'title' => t('Updating annotation nodes'),
      'operations' => $operations,
      'finished' => '\Drupal\makeshift_module\Form\BatchProcessRemodelForm::batchFinished',
    ];
  
    batch_set($batch);
  }

  public static function updateNodeField($chunk, &$context) {
    foreach ($chunk as $nid) {
      $node = \Drupal\node\Entity\Node::load($nid);
      $annotations = $node->get('field_annotation_reference')->referencedEntities();
      $referenceId = preg_match("/\/node\/(\d+)/", $node->get('field_annotation_collection_url')->getString(), $matches) ? (int)$matches[1] : null;
      if (!$referenceId) {
        continue;
      }
      foreach ($annotations as $annotation) {
        $annotation->set('field_annotation_node_reference', $referenceId);
        $annotation->set('field_annotation_target_field', $annotation->get('field_annotation_target_element')->getString());
        $annotation->set('field_image_annotation_position', $annotation->get('field_annotation_image_value')->getString() ?? NULL);
        $annotation->set('field_image_annotation_source', $annotation->get('field_annotation_image_source')->getString() ?? NULL);
        $annotation->set('field_text_annotation_end', $annotation->get('field_annotation_target_end')->getString() ?? NULL);
        $annotation->set('field_text_annotation_start', $annotation->get('field_annotation_target_start')->getString() ?? NULL);
        $annotation->set('field_text_annotation_exact', $annotation->get('field_annotation_target_exact')->getString() ?? NULL);
        if ($annotation->get('field_annotation_type')->getString() == 'Annotation'){
          $annotation->set('field_annotation_type', 'Text');
        }
        else {
          $annotation->set('field_annotation_type', 'Image');
        }
        $annotation->save();
        self::updateTextualbody($annotation);
      }
      $context['results'][] = $nid;
      $context['message'] = t('Updating node @nid', ['@nid' => $nid]);
    }
  }

  public static function updateTextualbody($annotation) {
    $textualbodies = $annotation->get('field_annotation_textualbodies')->referencedEntities();
    $paragraphs = [];
    foreach ($textualbodies as $textualbody) {
      $params = [
        'type' => 'annotation_textualbody',
        'field_annotation_purpose' => $textualbody->get('field_annotation_purpose')->getString(),
        'field_annotation_last_modified' => $textualbody->get('field_annotation_modified')->getString(),
        'field_annotation_date_created' => $textualbody->get('field_annotation_created')->getString(),
        'field_annotation_creator' => preg_match("/\/user\/(\d+)/", $textualbody->get('field_annotation_creator_id')->getString(), $matches) ? (int)$matches[1] : null
      ];
      if ($textualbody->get('field_annotation_purpose')->getString() == "tagging") {
        $tag = $textualbody->get('field_annotation_tag_reference')->entity;
        if (!$tag) {
          continue;
        }
        $params['field_annotation_tag_reference'] = $tag->id();
      }
      else {
        $params['field_annotation_comment'] = $textualbody->get('field_annotation_value')->getString();
      }
      $paragraph = Paragraph::create($params);
      $paragraph->save();
      $paragraphs[] = [
        'target_id' => $paragraph->id(),
        'target_revision_id' => $paragraph->getRevisionId(),
      ];
    }
    $annotation->set('field_annotation_textualbody', $paragraphs);
    $annotation->save();
  }

  public static function batchFinished($success, $results, $operations) {
    if ($success) {
      $message = \Drupal::translation()->formatPlural(
        count($results),
        'One post processed.', 
        '@count posts processed.'
      );
    }
    else {
      $message = t('Finished with an error.');
    }
  
    \Drupal::messenger()->addMessage($message);
  }
}