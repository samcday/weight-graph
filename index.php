<?php

use Carbon\Carbon;
use Silex\Application;
use Silex\Provider\SessionServiceProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

error_reporting(E_ALL & ~E_NOTICE);
require_once("vendor/autoload.php");

Dotenv::load(__DIR__);

$rkDateFormat = "D, d M Y H:i:s";

$dateCutoff = new DateTime("2013-10-22");

$app = new Silex\Application();
$app->register(new SessionServiceProvider());
$app->register(new Silex\Provider\TwigServiceProvider(), [
    "twig.path" => __DIR__ . "/views",
]);

$rk = new RunKeeperAPI(__DIR__ . "/rk-config.yml");
function hackPrivateVar($obj, $prop, $val) {
    $class = new ReflectionClass(get_class($obj));
    $prop = $class->getProperty($prop);
    $prop->setAccessible(true);
    $prop->setValue($obj, $val);
}


// Urgh.
hackPrivateVar($rk, "client_id", $_ENV["RUNKEEPER_CLIENT_ID"]);
hackPrivateVar($rk, "client_secret", $_ENV["RUNKEEPER_CLIENT_SECRET"]);

$app->before(function() use($app, $rk) {
    if($app["request_context"]->getPathInfo() == "/token") {
        return;
    }

    if(!$app["session"]->get("token")) {
        return new RedirectResponse($rk->connectRunkeeperButtonUrl());
    }
    else {
        $rk->setRunkeeperToken($app["session"]->get("token"));
    }
});

$app->get("/", function() use($app) {
    return $app["twig"]->render("index.twig");
});

$app->get("/weight", function(Request $req) use($app, $rk, $rkDateFormat, $dateCutoff) {
    $response = new JsonResponse();

    $page = 0;
    $weightItems = [];
    while(true) {
        $data = $rk->doRunkeeperRequest("WeightSet", "Read", null, null, [
            "page" => $page++
        ]);
        foreach ($data->items as $item) {
            $timestamp = Carbon::createFromFormat($rkDateFormat, $item->timestamp, new DateTimeZone("UTC"));
            if($response->getLastModified() == null) {
                $response->setLastModified($timestamp);
                $response->setPublic();
                if($response->isNotModified($req)) {
                    return $response;
                }
            }
            if($lastModified == null) {
                $lastModified = $timestamp;
            }
            if($timestamp < $dateCutoff) {
                break 2;
            }
            if(!$timestamp->copy()->startOfDay()->eq($timestamp)) {
                $weightItems[] = [
                    "timestamp" => $timestamp->setTimezone(new DateTimeZone("Australia/Brisbane"))->getTimestamp() * 1000,
                    "weight" => $item->weight
                ];
            }
        }
        if(empty($data->items)) {
            break;
        }
    }
    $response->setData($weightItems);
    return $response;
});

$app->get("/token", function(Request $req) use($app, $rk) {
    if($rk->getRunkeeperToken($req->get("code")) == true) {
        $app["session"]->set("token", $rk->access_token);
        return $app->redirect("/");
    }
    else {
        return "ERROR: failed to get token :(";
    }
});

$app->run();
