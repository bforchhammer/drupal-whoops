<?php
/**
 * @file
 * Contains Drupal\whoops\EventSubscriber.
 */

namespace Drupal\whoops;

use Drupal\Core\EventSubscriber\DefaultExceptionSubscriber;
use Drupal\Core\Utility\Error;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Whoops\Handler\JsonResponseHandler;
use Whoops\Handler\PlainTextHandler;
use Whoops\Handler\PrettyPageHandler;
use Whoops\Run as WhoopsRuntime;

class EventSubscriber extends DefaultExceptionSubscriber implements EventSubscriberInterface {
  /** @var PrettyPageHandler */
  protected $htmlHandler;
  /** @var JsonResponseHandler */
  protected $ajaxHandler;
  /** @var WhoopsRuntime */
  protected $whoops;

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::REQUEST][] = array('registerWhoops', 500);
    $events[KernelEvents::EXCEPTION][] = array('onException');
    return $events;
  }

  /**
   * @param GetResponseForExceptionEvent $event
   */
  public function onException(GetResponseForExceptionEvent $event) {
    // Leave proper response handling to Drupal.
    $this->whoops->sendHttpCode(FALSE);
    parent::onException($event);
  }

  /**
   * Creates an instance of the Whoops runtime, and registers handlers for
   * catching fatal errors, which are not caught by Drupal core. Other
   * exceptions are handled via the EXCEPTION kernel event, errors are left to
   * be handled by core (i.e. usually printed out in the message area).
   *
   * @param GetResponseEvent $event
   */
  public function registerWhoops(GetResponseEvent $event) {
    $this->whoops = new WhoopsRuntime();

    // Disable xdebug stack traces, ours are way better! :) Also, xdebug
    // stacktraces don't work well with JSON responses.
    if (function_exists('xdebug_disable')) {
      xdebug_disable();
    }

    // Let whoops only handled fatal errors directly.
    register_shutdown_function(array(
      $this->whoops,
      WhoopsRuntime::SHUTDOWN_HANDLER
    ));

    if (PHP_SAPI === 'cli') {
      $this->whoops->pushHandler(new PlainTextHandler());
    }
    else {
      // Register default handlers for pretty HTML and JSON.
      $this->htmlHandler = new PrettyPageHandler();
      $this->whoops->pushHandler($this->htmlHandler);

      $this->ajaxHandler = new JsonResponseHandler();
      $this->ajaxHandler->addTraceToOutput(TRUE);
      $this->ajaxHandler->onlyForAjaxRequests(TRUE);
      $this->whoops->pushHandler($this->ajaxHandler);
    }
  }

  /**
   * @param GetResponseForExceptionEvent $event
   */
  protected function onJson(GetResponseForExceptionEvent $event) {
    $exception = $event->getException();
    $error = Error::decodeException($exception);

    // Display the message if the current error reporting level allows this type
    // of message to be displayed,
    $data = NULL;
    if (error_displayable($error)) {
      // We have already determined that we need a JSON response.
      $this->ajaxHandler->onlyForAjaxRequests(FALSE);
      // Get output and json_decode() it for the JsonResponse below.
      $data = json_decode($this->whoops->handleException($exception));
    }

    $response = new JsonResponse($data, Response::HTTP_INTERNAL_SERVER_ERROR);
    if ($exception instanceof HttpExceptionInterface) {
      $response->setStatusCode($exception->getStatusCode());
    }

    $event->setResponse($response);
  }

  /**
   * @param GetResponseForExceptionEvent $event
   */
  protected function onHtml(GetResponseForExceptionEvent $event) {
    $exception = $event->getException();
    $error = Error::decodeException($exception);

    // Display the message if the current error reporting level allows this type
    // of message to be displayed, and unconditionally in update.php.
    if (error_displayable($error)) {
      $output = $this->whoops->handleException($event->getException());
      $response = new Response($output);
      if ($exception instanceof HttpExceptionInterface) {
        $response->setStatusCode($exception->getStatusCode());
      }
      else {
        $response->setStatusCode(Response::HTTP_INTERNAL_SERVER_ERROR, '500 Service unavailable (with message)');
      }
      $event->setResponse($response);
    }
  }

}
