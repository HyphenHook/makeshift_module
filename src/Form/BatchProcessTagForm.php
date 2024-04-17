<?php

namespace Drupal\makeshift_module\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

class BatchProcessTagForm extends FormBase {

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
      '#value' => $this->t('Run Batch Tag Process'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $nids = \Drupal::entityQuery('node')
    ->condition('type', 'annotation_textualbody')
    ->condition('field_annotation_purpose', 'tagging')
    ->accessCheck(FALSE)
    ->execute();

    $chunks = array_chunk($nids, 30);
    $operations = [];

    foreach ($chunks as $chunk) {
      $operations[] = [
        '\Drupal\makeshift_module\Form\BatchProcessTagForm::updateNodeField',
        [$chunk],
      ];
    }
    $batch = [
      'title' => t('Updating annotation nodes'),
      'operations' => $operations,
      'finished' => '\Drupal\makeshift_module\Form\BatchProcessTagForm::batchFinished',
    ];
  
    batch_set($batch);
  }

  public static function updateNodeField($chunk, &$context) {
    $config = \Drupal::config('recogito_integration.settings');
    $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    foreach ($chunk as $nid) {
      $node = \Drupal\node\Entity\Node::load($nid);
      $vocab = $config->get('recogito_integration.annotation_vocab_name');
      $terms = $term_storage->loadByProperties(['name' => $node->get('field_annotation_value')->getString(), 'vid' => $vocab]);
      if (!$terms) {
        continue;
      }
      $term = reset($terms);
      $node->set('field_annotation_tag_reference', $term->id());
      $node->save();
      $context['results'][] = $nid;
      $context['message'] = t('Updating node @nid', ['@nid' => $nid]);
    }
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