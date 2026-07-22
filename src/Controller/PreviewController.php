<?php

declare(strict_types=1);

namespace Drupal\varbase_ai_figma\Controller;

use Drupal\canvas\Entity\Page;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Serves token-gated previews of Canvas pages for the headless browser.
 *
 * A short-lived state token (minted by the
 * varbase_ai_figma:preview_token_url function call) lets an automated,
 * unauthenticated browser (browser_preview / browser_navigate) render an
 * UNPUBLISHED canvas_page without a one-time login link. The token is bound to
 * a single page id and expires after 300 seconds; access is granted with
 * cache max-age 0 so an in-progress draft is always re-rendered fresh.
 */
class PreviewController extends ControllerBase {

  /**
   * The expirable token store (per-key atomic + auto-expiring).
   */
  protected KeyValueStoreExpirableInterface $tokenStore;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->tokenStore = $container->get('keyvalue.expirable')->get('varbase_ai_figma.preview_tokens');
    return $instance;
  }

  /**
   * Checks access for the preview route using a token query parameter.
   *
   * @param \Drupal\canvas\Entity\Page $canvas_page
   *   The canvas page entity.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result. Never cached, so a token can be revoked (or expire)
   *   without a stale cached allow.
   */
  public function access(Page $canvas_page, Request $request): AccessResultInterface {
    $token = (string) $request->query->get('token');
    if ($token === '') {
      return AccessResult::forbidden('Missing preview token.')->setCacheMaxAge(0);
    }

    // Expirable key/value store: per-key atomic, auto-expiring, and never
    // written on the read path (no anonymous write-amplification or race).
    $page_id = $this->tokenStore->get($token);
    if ($page_id === NULL) {
      return AccessResult::forbidden('Invalid or expired preview token.')->setCacheMaxAge(0);
    }
    if ((string) $page_id !== (string) $canvas_page->id()) {
      return AccessResult::forbidden('Token does not match this page.')->setCacheMaxAge(0);
    }
    // Single-use: invalidate immediately so the URL cannot be replayed (it can
    // leak via access logs or browser history for an unpublished draft).
    $this->tokenStore->delete($token);
    return AccessResult::allowed()->setCacheMaxAge(0);
  }

  /**
   * Renders the full view of a Canvas page for preview.
   *
   * @param \Drupal\canvas\Entity\Page $canvas_page
   *   The canvas page entity, published or not.
   *
   * @return array
   *   A render array of the page in its "full" view mode.
   */
  public function view(Page $canvas_page): array {
    $build = $this->entityTypeManager()
      ->getViewBuilder('canvas_page')
      ->view($canvas_page, 'full');

    // Never cache the preview: the underlying draft changes on every agent
    // edit, and the access grant is already max-age 0.
    $build['#cache']['max-age'] = 0;

    return $build;
  }

}
