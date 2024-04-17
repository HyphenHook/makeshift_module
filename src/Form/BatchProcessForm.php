<?php

namespace Drupal\makeshift_module\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

class BatchProcessForm extends FormBase {

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
      '#value' => $this->t('Run Batch Process'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $nids = \Drupal::entityQuery('node')
    ->condition('type', 'annotation')
    ->accessCheck(FALSE)
    ->execute();

    $chunks = array_chunk($nids, 30);
    $operations = [];

    foreach ($chunks as $chunk) {
      $operations[] = [
        '\Drupal\makeshift_module\Form\BatchProcessForm::updateNodeField',
        [$chunk],
      ];
    }
    $batch = [
      'title' => t('Updating annotation nodes'),
      'operations' => $operations,
      'finished' => '\Drupal\makeshift_module\Form\BatchProcessForm::batchFinished',
    ];
  
    batch_set($batch);
  }

  public static function updateNodeField($chunk, &$context) {
    foreach ($chunk as $nid) {
      $node = \Drupal\node\Entity\Node::load($nid);
      $node->set('field_annotation_target_element', 'body');
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