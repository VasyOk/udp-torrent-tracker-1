<?php
/**
 * Created by PhpStorm.
 * User: Chris
 * Date: 11-5-2014
 * Time: 09:33
 */

namespace Devristo\TorrentTracker\TcpServer;


use Devristo\Torrent\Bee;
use Devristo\TorrentTracker\Message\AnnounceRequest;
use Devristo\TorrentTracker\Message\AnnounceResponse;
use Devristo\TorrentTracker\Message\TrackerResponse;
use Devristo\TorrentTracker\Message\TrackerRequest;
use Devristo\TorrentTracker\Message\ErrorResponse;
use Devristo\TorrentTracker\Message\ScrapeRequest;
use Devristo\TorrentTracker\Message\ScrapeResponse;
use Devristo\TorrentTracker\Model\SwarmPeer;
use Guzzle\Http\Message\Request;

use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class Serializer {
    protected $routes;
    protected $decodeHandlers;
    protected $encodeHandlers;
    protected $bee;

    public function __construct(){
        $this->routes = new RouteCollection();
        $this->routes->add('announce', new Route('/announce'));
        $this->routes->add('scrape', new Route('/scrape'));

        $this->bee = new Bee();

        $this->decodeHandlers = array(
            "announce" => array($this, 'decodeAnnounce'),
            "scrape" => array($this, 'decodeScrape')
        );

        $this->encodeHandlers = array(
            "announce" => array($this, 'encodeAnnounce'),
            "scrape" => array($this, 'encodeScrape'),
            "error" => array($this, 'encodeError')
        );
    }

    /**
     * @param TrackerResponse $response
     * @return string
     */
    public function encode(TrackerResponse $response){
        $handler = $this->encodeHandlers[$response->getMessageType()];
        return call_user_func($handler, $response);
    }

    /**
     * @param Request $httpRequest
     * @return TrackerRequest
     */
    public function decode(Request $httpRequest){
        $context = new RequestContext();
        $context->setMethod($httpRequest->getMethod());
        $matcher = new UrlMatcher($this->routes, $context);
        $parameters = $matcher->match($httpRequest->getPath());

        $handler = $this->decodeHandlers[$parameters['_route']];

        return call_user_func($handler, $httpRequest);
    }

    public function decodeAnnounce(Request $httpRequest){
        $eventMap = array(
            "started" => AnnounceRequest::EVENT_STARTED,
            "stopped" => AnnounceRequest::EVENT_STOPPED,
            "completed" => AnnounceRequest::EVENT_COMPLETED,
            null => AnnounceRequest::EVENT_NONE
        );

        $query = $httpRequest->getQuery();

        $announce = new AnnounceRequest();
        $announce->setInfoHash($query['info_hash']);
        $announce->setPeerId($query['peer_id']);
        $announce->setPort(intval($query['port'], 10));
        $announce->setUploaded($query['uploaded']);
        $announce->setDownloaded($query['downloaded']);
        $announce->setLeft($query['left']);
        $announce->setRequestString($httpRequest->getResource());

        $eventKey = array_key_exists('event', $query) && array_key_exists($query['event'], $eventMap)
            ? $query['event'] : null;
        $announce->setEvent($eventMap[$eventKey]);

        if(array_key_exists('ip', $query))
            $announce->setIpv4($query['ip']);

        if(array_key_exists('numwant', $query))
            $announce->setNumWant($query['numwant']);

        if(array_key_exists('key', $query))
            $announce->setKey($query['key']);

        return $announce;
    }

    public function decodeScrape(Request $httpRequest){
        $hashes = $httpRequest->getQuery()['info_hash'];

        if (is_string($hashes))
            $hashes = array($hashes);

        $scrape = new ScrapeRequest();
        $scrape->setInfoHashes($hashes);
        return $scrape;
    }

    public function encodeScrape(ScrapeResponse $scrape){
        return $this->bee->encode(array(
            'files' => array_map(function($infoHash) use ($scrape){
                $stats = $scrape->getStats()[$infoHash];

                return array(
                    'complete' => $stats['complete'],
                    'downloaded' => $stats['downloaded'],
                    'incomplete' => $stats['incomplete']
                );
            }, $scrape->getRequest()->getInfoHashes())
        ));
    }

    public function encodeAnnounce(AnnounceResponse $announce){
        $peers = array_map(function(SwarmPeer $peer){
            return array(
                'ip' => $peer->getIp(),
                'port' => $peer->getPort()
            );
        }, $announce->getPeers());

        return $this->bee->encode(array(
            'interval' => $announce->getInterval(),
            'complete' => $announce->getSeeders(),
            'incomplete' => $announce->getLeechers(),
            'peers' => $peers
        ));
    }

    public function encodeError(ErrorResponse $error){
        return $this->bee->encode(array(
            'failure reason' => $error->getMessage()
        ));
    }
} 