<?php
/**
 * @file
 * Contains Drupal\whoops\EventSubscriber.
 */

namespace Drupal\whoops;

use Drupal\Core\ContentNegotiation;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class EventSubscriber implements EventSubscriberInterface {
  /**
   * @var \Whoops\Run
   */
  private $whoops;

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::REQUEST][] = array('registerWhoops', 500);
    $events[KernelEvents::EXCEPTION][] = array('onException', 500);
    return $events;
  }

  public function registerWhoops(GetResponseEvent $event) {
    $this->whoops = new \Whoops\Run();
    $this->whoops->silenceErrorsInPaths('/.*/', E_NOTICE);
    $this->whoops->register();
  }

  /**
   * Handles errors for this subscriber.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent $event
   *   The event to process.
   */
  public function onException(GetResponseForExceptionEvent $event) {
    $format = $this->getFormat($event->getRequest());

    switch ($format) {
      case 'json':
        $this->whoops->pushHandler(new \Whoops\Handler\JsonResponseHandler);
        break;
      case 'html':
      default:
        $this->whoops->pushHandler(new \Whoops\Handler\PrettyPageHandler());
        break;
    }
    $output = $this->whoops->handleException($event->getException());
    $event->setResponse(new Response($output));
  }

  /**
   * Gets the error-relevant format from the request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return string
   *   The format as which to treat the exception.
   */
  protected function getFormat(Request $request) {
    // @todo We are trying to switch to a more robust content negotiation
    // library in https://www.drupal.org/node/1505080 that will make
    // $request->getRequestFormat() reliable as a better alternative
    // to this code. We therefore use this style for now on the expectation
    // that it will get replaced with better code later. This approach makes
    // that change easier when we get to it.
    $conneg = new ContentNegotiation();
    $format = $conneg->getContentType($request);

    // These are all JSON errors for our purposes. Any special handling for
    // them can/should happen in earlier listeners if desired.
    if (in_array($format, ['drupal_modal', 'drupal_dialog', 'drupal_ajax'])) {
      $format = 'json';
    }

    // Make an educated guess that any Accept header type that includes "json"
    // can probably handle a generic JSON response for errors. As above, for
    // any format this doesn't catch or that wants custom handling should
    // register its own exception listener.
    foreach ($request->getAcceptableContentTypes() as $mime) {
      if (strpos($mime, 'html') === FALSE && strpos($mime, 'json') !== FALSE) {
        $format = 'json';
      }
    }

    return $format;
  }


}
