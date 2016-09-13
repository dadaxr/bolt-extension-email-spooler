<?php

namespace Bolt\Extension\Bolt\EmailSpooler\EventListener;

use Bolt\Filesystem\Filesystem;
use Bolt\Filesystem\Handler\File;
use Silex\Application;
use Swift_FileSpool as SwiftFileSpool;
use Swift_Mailer as SwiftMailer;
use Swift_Transport_SpoolTransport as SwiftTransportSpoolTransport;
use Swift_TransportException as SwiftTransportException;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\HttpKernel\Event\PostResponseEvent;

/**
 * Email queue processing listener.
 *
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 */
class QueueListener
{
    /** @var Application */
    private $app;

    /**
     * Constructor.
     *
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Handle the processing of the SMTP queue.
     *
     * @param Event|null $event
     */
    public function flush(Event $event = null)
    {
        /** @var SwiftMailer $mailer */
        $mailer = $this->app['mailer'];
        /** @var SwiftTransportSpoolTransport $transport */
        $transport = $mailer->getTransport();
        /** @var SwiftFileSpool $spool */
        $spool = $transport->getSpool();
        if ($event instanceof PostResponseEvent) {
            try {
                $spool->flushQueue($this->app['swiftmailer.transport']);
            } catch (SwiftTransportException $e) {
            }
        } else {
            $spool->flushQueue($this->app['swiftmailer.transport']);
        }
    }

    /**
     * Retry sending the contents of the SMTP queue.
     *
     * @return array
     */
    public function retry()
    {
        $failedRecipients = [];
        /** @var SwiftMailer $mailer */
        $mailer = $this->app['mailer'];

        if ($this->app['cache']->contains('mailer.queue.timer')) {
            return $failedRecipients;
        }
        $this->app['cache']->save('mailer.queue.timer', true, 600);

        /** @var Filesystem $cacheFs */
        $cacheFs  = $this->app['filesystem']->getFilesystem('cache');
        if (!$cacheFs->has('.spool')) {
            return $failedRecipients;
        }

        $spooled = $cacheFs
            ->find()
            ->files()
            ->ignoreDotFiles(false)
            ->in('.spool')
            ->name('*.message')
        ;

        /** @var File $spool */
        foreach ($spooled as $spool) {
            // Unserialise the data
            $message = unserialize($spool->read());

            // Back up the file
            $spool->rename($spool->getPath() . '.processing');

            // Dispatch, again.
            $mailer->send($message, $failedRecipients);

            // Remove the file and retry
            $spool->delete();
        }

        return $failedRecipients;
    }
}
