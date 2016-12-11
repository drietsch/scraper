<?php

    error_reporting(-1);
    ini_set('display_errors', 'On');
    require 'vendor/autoload.php';

    $database = new MongoDB\Client;
    $collection = $database->selectCollection('willhaben','homes');

    $query = array('fk' => 185118513);

    $cursor = $collection->find($query);

    foreach ($cursor as $document)
    {
        foreach ($document->images as $image)
        {
            echo '<img src="' . $image->image . '">';
        }
    }
