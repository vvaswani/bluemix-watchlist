<?php
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

// autoload files
require '../vendor/autoload.php';
require '../config.php';

// if VCAP_SERVICES environment available
// overwrite local credentials with environment credentials
if ($services = getenv("VCAP_SERVICES")) {
  $services_json = json_decode($services, true);
  $config['settings']['db']['uri'] = $services_json['cloudantNoSQLDB'][0]['credentials']['url'];
} 

// configure Slim application instance
// initialize application
$app = new \Slim\App($config);

// initialize dependency injection container
$container = $app->getContainer();

// add view renderer to DI container
$container['view'] = function ($container) {
  $view = new \Slim\Views\Twig("../templates/");
  $router = $container->get('router');
  $uri = \Slim\Http\Uri::createFromEnvironment(new \Slim\Http\Environment($_SERVER));
  $view->addExtension(new \Slim\Views\TwigExtension($router, $uri));  
  return $view;
};

// configure and add TMDB client to DI container
$container['tmdb'] = function ($container) {
  return new Client([
    'base_uri' => 'https://api.themoviedb.org',
    'timeout'  => 6000,
    'verify' => false,    // set to true in production
  ]);
};

// configure and add Cloudant client to DI container
$container['cloudant'] = function ($container) use ($config) {
  return new Client([
    'base_uri' => $config['settings']['db']['uri'] . '/',
    'timeout'  => 6000,
    'verify' => false,    // set to true in production
  ]);
};

// welcome page controller
$app->get('/', function (Request $request, Response $response) {
  return $response->withHeader('Location', $this->router->pathFor('home'));
});

$app->get('/home', function (Request $request, Response $response) {
  $config = $this->get('settings');
  
  // get all docs in database
  // include all content
  $dbResponse = $this->cloudant->get($config['db']['name'] . '/_all_docs', [
    'query' => ['include_docs' => 'true'] 
  ]);
  if($response->getStatusCode() == 200) {
    $json = (string)$dbResponse->getBody();
    $body = json_decode($json);
  }
  
  // provide results to template
  $response = $this->view->render($response, 'home.twig', [
    'router' => $this->router, 'items' => $body->rows
  ]);
  return $response;
})->setName('home');

// search page handler
$app->post('/search', function (Request $request, Response $response, $args) {
  $config = $this->get('settings');
  $params = $request->getParams();
  if (!($q = filter_var($params['q'], FILTER_SANITIZE_STRING))) {
    throw new Exception('ERROR: Query is not a valid string');
  }
  
  // search TMDb API for matches
  $apiResponse = $this->tmdb->get('/3/search/multi', [
    'query' => [
      'api_key' => $config['tmdb']['key'],
      'query' => $q
    ]
  ]);  
  
  // decode TMDb API response
  // provide results to template
  if ($apiResponse->getStatusCode() == 200) {
    $json = (string)$apiResponse->getBody();
    $body = json_decode($json);
  }  

  $response = $this->view->render($response, 'search.twig', [
    'router' => $this->router,
    'q' => $q, 
    'results' => $body->results
  ]);
  return $response;
})->setName('search');

// similarity search handler
$app->get('/search/similar/{type}/{id}', function (Request $request, Response $response, $args) {
  $config = $this->get('settings');
  
  // get item type
  $type = $args['type'];  
  if (!(in_array($type, array('tv', 'movie')))) {
    throw new Exception('ERROR: Type is not valid');
  }
  
  // get item ID on TMDb
  $id = filter_var($args['id'], FILTER_SANITIZE_NUMBER_INT);

  // search TMDb API for similar items
  $apiResponse = $this->tmdb->get("/3/$type/$id/similar", [
    'query' => [
      'api_key' => $config['tmdb']['key']
    ]
  ]);  
  
  // decode TMDb API response
  // provide results to template
  if ($apiResponse->getStatusCode() == 200) {
    $json = (string)$apiResponse->getBody();
    $body = json_decode($json);
  }  

  $response = $this->view->render($response, 'search.twig', [
    'router' => $this->router,
    'results' => $body->results
  ]);
  return $response;
})->setName('search-similar');

// add page handler
$app->get('/list/save/{type}/{id}', function (Request $request, Response $response, $args) {
  $config = $this->get('settings');
  
  // get item type
  $type = $args['type'];  
  if (!(in_array($type, array('tv', 'movie')))) {
    throw new Exception('ERROR: Type is not valid');
  }
  
  // get item ID on TMDb
  $id = filter_var($args['id'], FILTER_SANITIZE_NUMBER_INT);
  
  // get item details from TMDb API
  $apiResponse = $this->tmdb->get("/3/$type/$id", [
    'query' => [
      'api_key' => $config['tmdb']['key'],
    ]
  ]);  
  
  // create JSON document containing relevant information
  // save document using Cloudant API
  if ($apiResponse->getStatusCode() == 200) {
    $json = (string)$apiResponse->getBody();
    $body = json_decode($json);
    $title = ($type == 'movie') ? $body->title : $body->name;
    $doc = [
      'id' => $body->id,
      'type' => $type,
      'title' => $title,
      'status' => '1',
    ];
    $this->cloudant->post($config['db']['name'], [
      'json' => $doc
    ]);
  }
  
  return $response->withHeader('Location', $this->router->pathFor('home'));
})->setName('save');

// status change handler
$app->get('/list/update/{id}/{status}', function (Request $request, Response $response, $args) {
  $config = $this->get('settings');
  
  // get document ID and revision ID in Cloudant
  $keys = explode('.', filter_var($args['id'], FILTER_SANITIZE_STRING));
  $id = $keys[0];
  $rev = $keys[1];
  
  // retrieve complete document from database using Cloudant API
  $dbResponse = $this->cloudant->get($config['db']['name'] . '/' . $id);
  $json = (string)$dbResponse->getBody();
  $doc = (array)json_decode($json);  
  
  // update document data
  // save document back to database using Cloudant API
  $doc['status'] = $args['status'];  
  $this->cloudant->put($config['db']['name'] . '/' . $id, [
    "query" => ["rev" => $rev], 'json' => $doc
  ]);
  
  return $response->withHeader('Location', $this->router->pathFor('home'));
})->setName('update-status');

// deletion handler
$app->get('/list/delete/{id}', function (Request $request, Response $response, $args) {
  $config = $this->get('settings');
  
  // get document ID and revision ID in Cloudant
  $keys = explode('.', filter_var($args['id'], FILTER_SANITIZE_STRING));
  $id = $keys[0];
  $rev = $keys[1];
  
  // delete document from database using Cloudant API
  $this->cloudant->delete($config['db']['name'] . '/' . $id, [ 
    "query" => ["rev" => $rev] 
  ]);
  
  return $response->withHeader('Location', $this->router->pathFor('home'));
})->setName('delete');

$app->run();
