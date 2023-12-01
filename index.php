<?php

require 'vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\InputMedia\ArrayOfInputMedia;
use TelegramBot\Api\Types\InputMedia\InputMedia;


/**
 * Class InputMediaPhoto
 * Represents a photo to be sent.
 *
 * @package TelegramBot\Api
 */
class InputMediaDocument extends InputMedia
{
    /**
     * InputMediaPhoto constructor.
     *
     * @param string $media
     * @param string|null $caption
     * @param string|null $parseMode
     */
    public function __construct($media, $caption = null, $parseMode = null)
    {
        $this->type = 'document';
        $this->media = $media;
        $this->caption = $caption;
        $this->parseMode = $parseMode;
    }
}

class ParserKvestinfo
{
    private $urls = [];
    private $outputData = '';
    private $successUrls = 0;
    private $failUrls = 0;

    /**
     * @return string
     */
    public function getOutputData(): string
    {
        return $this->outputData;
    }

    public function processSitemap($sitemapUrl, $client): void
    {
        $requestCounter = 0;

        try {
            $this->prepareListUrls($sitemapUrl, $client);

            $urlsCount = count($this->urls);

            $position = 0;
            $positionFile = __DIR__ . '/positions/' . md5($sitemapUrl) . '.txt';

            if (file_exists($positionFile)) {
                $position = (int)file_get_contents($positionFile);
            }

            if ($position >= $urlsCount) {
                $position = 0;
            }

            // Обрезаем массив URL до сохраненной позиции
            $urls = array_slice($this->urls, $position);

            foreach ($urls as $url) {
                $this->processUrl($url, $client, $requestCounter);

                $position++;
                // Сохраняем новую позицию в файл
                file_put_contents($positionFile, $position);
            }
        } catch (Exception $e) {
            $this->outputData .= "Error processing sitemap: " . $e->getMessage() . "\n";
        } finally {
            sleep(1);
        }
    }

    /**
     * @return mixed
     */
    public function getUrls(): mixed
    {
        return $this->urls;
    }

    public function getSuccessUrls(): int
    {
        return $this->successUrls;
    }

    public function getFailUrls(): int
    {
        return $this->failUrls;
    }

    private function processUrl($url, $client, &$requestCounter): void
    {
        try {
            $response = $client->head($url);
            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                $this->outputData .= "URL: $url, Status Code: $statusCode\n";
            }
            $this->successUrls++;
        } catch (RequestException $e) {
            $this->outputData .= "URL: $url, Error: " . $e->getMessage() . "\n";
            $this->failUrls++;
        } finally {
            $requestCounter++;

            if ($requestCounter % 10 === 0) {
                sleep(1);
                $requestCounter = 0;
            }
        }
    }

    private function prepareListUrls($sitemapUrl, $client)
    {
        $response = $client->get($sitemapUrl);
        $xml = simplexml_load_string($response->getBody());

        foreach ($xml->url as $url) {
            $this->urls[] = (string)$url->loc;
        }

        foreach ($xml->sitemap as $nestedSitemap) {
            $this->prepareListUrls((string)$nestedSitemap->loc, $client);
        }
    }
}

$message = '';
$documents = [];
$bot = new BotApi($_ENV['TG_BOT_TOKEN']);
$site = 'https://www.' . $_ENV['DOMAIN'] . '/';
$chatId = $_ENV['TG_CHAT_ID'];
@mkdir('./positions');

echo 'Start...' . PHP_EOL;

try {
    $client = new Client();
    $indexPage = $client->get($site);
    $body = $indexPage->getBody()->getContents();
    preg_match_all('/\/\/([a-z-]+)\.' . $_ENV['DOMAIN'] . '\'/s', $body, $outputArray);
    if (!isset($outputArray[1])) {
        throw new RuntimeException('Not found list of subdomains');
    }
    $outputArray[1] = ['www'] + $outputArray[1];
    $cityList = array_unique(array_filter($outputArray[1]));

    $mediaGroups = new ArrayOfInputMedia();
    foreach ($cityList as $city) {
        $sitemapUrl = 'https://' . $city . '.' . $_ENV['DOMAIN'] . '/sitemap.xml';

        echo 'Parse: ' . $sitemapUrl . PHP_EOL;

        $clientSitemap = new Client();
        $parser = new ParserKvestinfo();
        $parser->processSitemap($sitemapUrl, $clientSitemap);

        if (strlen($parser->getOutputData()) > 0) {
            $documents[$city] = new CURLStringFile($parser->getOutputData(), 'invalid_urls_' . $city . '_' . date('Y-m-d-H-i') . '.txt');
            $m = $city . ': ' . $parser->getFailUrls() . ' FAIL' . PHP_EOL;
        } else {
            $m = $city . ': ' . $parser->getSuccessUrls() . ' OK' . PHP_EOL;
        }

        $message .= $m;
        echo $m;
    }
} catch (Exception $e) {
    $m = 'Error main process: ' . $e->getMessage() . PHP_EOL;
    echo $m;

    $message .= $m;
} finally {
    $mediaGroup = [];
    $files = [];
    foreach ($documents as $doc) {
        $mediaGroup[] = new InputMediaDocument('attach://' . $doc->postname);
        $files[$doc->postname] = $doc;
    }

    if (count($mediaGroup) > 0) {
        $arrayMediaGroups = array_chunk($mediaGroup, 10);
        foreach($arrayMediaGroups as $group) {
            $bot->sendMediaGroup($chatId, new ArrayOfInputMedia($group), false, null, null, null, null, $files);
        }
    }

    $bot->sendMessage($chatId, $message);
}