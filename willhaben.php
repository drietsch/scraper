<?php


    //docker run -p 127.0.0.1:$HOSTPORT:$CONTAINERPORT --name CONTAINER -t someimage

    //docker run -p 27017:27017 --name mongodb_container -t mongo
    //docker run --restart=always -d -p 8080:8080 --name nominatim-europe nominatim


//git clone https://github.com/mediagis/nominatim-docker.git
//https://github.com/mediagis/nominatim-docker

    require 'vendor/autoload.php';
    use Goutte\Client;
    use Symfony\Component\DomCrawler\Crawler;
    use Monolog\Logger;
    use Monolog\Handler\StreamHandler;

    $log = new Logger('willhaben');
    $log->pushHandler(new StreamHandler('./willhaben.log', Logger::INFO));

    $rows = 100;
    $host = "https://www.willhaben.at";
    $url = $host . "/iad/immobilien/haus-kaufen/haus-angebote";
    $itemUrls = Array();

    $client = new Client();
    $crawler = $client->request('GET', $url . '?rows=' . $rows);
    $totalItems = $crawler->filter('#search-count')->text();
    $totalItems = intval(str_replace(".","",$totalItems));
    $pages = ceil($totalItems/$rows);

    $log->addInfo('Getting number of pages: ' . $pages);

    //$pages = 1;
    $items = Array();

    for ($i=1;$i<=$pages;$i++)
    {
        $crawler = $client->request('GET', $url . '?rows=' . $rows . "&page=" . $i);
        $crawler->filter('a[itemprop="url"]')->each(function (Crawler $node) {
            global $log;
            global $host;
            global $items;
            $furl = $node->attr("href");
            preg_match_all('!-\d+/!', $furl, $matches);
            $fk = intval(str_replace(array("/","-"),"",$matches[0])[0]);
            $item["fk"] = $fk;
            $item["furl"] = $host . $furl;
            $item["vendor"] = "willhaben";
            $item["active"] = true;
            $items[] = $item;
        });
        $log->addInfo('Getting new overview page: ' . $url . '?rows=' . $rows . "&page=" . $i);
        sleep(0.5);
    }

    $database = new MongoDB\Client;
    $collection = $database->selectCollection('willhaben','homes');
    $collection->drop();
    $i = 1;
    foreach ($items as $item)
    {
        $crawler = $client->request('GET', $item["furl"]);

        try {
            $tempName = explode("<", trim($crawler->filter('h1[itemprop="name"]')->html()));
            $item["name"] = trim($tempName[0]);
        } catch (Exception $e) {
        }

        try {
            $tempAddress = explode("<br>", trim($crawler->filter('dd[itemprop="Address"]')->html()));
            $item["address"]["summary"] = join(",", $tempAddress);

            $osmUrl = "http://localhost:8080/search.php?q=" . urlencode($item["address"]["summary"]) . "&format=json&addressdetails=1";

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $osmUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $osm = curl_exec($ch);
            curl_close($ch);

            $item["address"]["osm"] = json_decode($osm);

        } catch (Exception $e) {

        }

        try {
            $item["description"] = $crawler->filter('div[itemprop="description"]')->html();
        } catch (Exception $e) {
        }

        $tempType = $crawler->filter('span[class="col-2-desc"]')->each(function (Crawler $node) {
            global $item;
            $attrKey = trim($node->text());
            $attrValue = trim($node->nextAll()->text());
            if ($attrKey && $attrValue)
                $item["attributes"][$attrKey] = $attrValue;
        });

        $tempImages = $crawler->filter('img[class="img-no-script"]')->each(function (Crawler $node) {
            global $item;
            global $log;
            $imageUrl = $node->attr("src");
            if ($imageUrl)
            {

                $filename = "./images/" . md5($imageUrl) . ".jpg";
                file_put_contents($filename, file_get_contents($imageUrl));
                $log->addInfo('Downloading image: ' . $imageUrl);
                $imageAttribute["url"] = $imageUrl;
                $imageAttribute["image"] = $filename;
                $item["images"][] = $imageAttribute;
            }
        });

        $collection->insertOne($item);

        $log->addInfo('Inserting new home ' . $i . ' into database: ' . $item["name"] . ", " . $item["address"]["summary"]);
        $i++;
    }

?>