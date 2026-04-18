<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_content;

use Drupal\menu_link_content\Entity\MenuLinkContent;

/**
 * Ensures the expected links exist in the Main navigation menu.
 */
final class MainNavigationManager {

  /**
   * Creates or updates the expected public navigation links.
   */
  public static function ensureMainNavigationLinks(): int {
    $storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');

    $links = [
      ['title' => 'Accueil', 'uri' => 'internal:/accueil', 'weight' => 0],
      ['title' => 'Services', 'uri' => 'internal:/services', 'weight' => 1],
      ['title' => 'IA & Drupal', 'uri' => 'internal:/ia-drupal', 'weight' => 2],
      ['title' => 'Cas clients', 'uri' => 'internal:/cas-clients', 'weight' => 3],
      ['title' => 'Contact', 'uri' => 'internal:/contact', 'weight' => 4],
    ];

    $created_or_updated = 0;

    foreach ($links as $link) {
      $candidate_ids = \Drupal::entityQuery('menu_link_content')
        ->accessCheck(FALSE)
        ->condition('menu_name', 'main')
        ->condition('title', $link['title'])
        ->execute();

      if ($candidate_ids) {
        $candidates = $storage->loadMultiple($candidate_ids);
        foreach ($candidates as $candidate) {
          $link_item = $candidate->get('link')->first();
          if ($link_item && ($link_item->getValue()['uri'] ?? '') === $link['uri']) {
            if ((int) $candidate->get('weight')->value !== $link['weight']) {
              $candidate->set('weight', $link['weight']);
              $candidate->save();
              $created_or_updated++;
            }

            continue 2;
          }

          $candidate->set('link', ['uri' => $link['uri']]);
          $candidate->set('enabled', TRUE);
          $candidate->set('weight', $link['weight']);
          $candidate->save();
          $created_or_updated++;
          continue 2;
        }
      }

      MenuLinkContent::create([
        'title' => $link['title'],
        'menu_name' => 'main',
        'link' => ['uri' => $link['uri']],
        'expanded' => FALSE,
        'enabled' => TRUE,
        'weight' => $link['weight'],
      ])->save();

      $created_or_updated++;
    }

    $legacy_home_ids = \Drupal::entityQuery('menu_link_content')
      ->accessCheck(FALSE)
      ->condition('menu_name', 'main')
      ->condition('title', 'Home')
      ->execute();

    if ($legacy_home_ids) {
      $legacy_homes = $storage->loadMultiple($legacy_home_ids);

      $accueil_ids = \Drupal::entityQuery('menu_link_content')
        ->accessCheck(FALSE)
        ->condition('menu_name', 'main')
        ->condition('title', 'Accueil')
        ->execute();

      foreach ($legacy_homes as $legacy_home) {
        if ($accueil_ids) {
          $legacy_home->delete();
        }
        else {
          $legacy_home->set('title', 'Accueil');
          $legacy_home->set('link', ['uri' => 'internal:/accueil']);
          $legacy_home->set('enabled', TRUE);
          $legacy_home->set('weight', 0);
          $legacy_home->save();
        }

        $created_or_updated++;
      }
    }

    return $created_or_updated;
  }

}
