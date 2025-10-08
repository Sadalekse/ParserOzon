<?php

class OzonDomParser
{
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';

    public function parseBySku($sku)
    {
        $url = "https://www.ozon.ru/product/{$sku}/";
        return $this->parseProductPage($url);
    }

    public function parseProductPage($url)
    {
        $html = $this->getPageContent($url);

        if (!$html) {
            throw new Exception("Не удалось загрузить страницу: {$url}. Вероятно, запрос заблокирован Ozon.");
        }

        if (strpos($html, 'Доступ ограничен') !== false || strpos($html, 'Проверка безопасности') !== false) {
            throw new Exception("Обнаружена страница блокировки Ozon. Возможно, требуется ввести капчу или сменить IP.");
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $allCharacteristics = $this->parseCharacteristicsWithXPath($xpath);

        return [
            'Название'             => $this->parseTitleWithXPath($xpath),
            'Категория'            => $this->parseCategoryWithXPath($xpath),
            'Изображения'          => $this->parseImagesWithXPath($xpath),
            'Описание'             => $this->parseDescriptionWithXPath($xpath),
            'Характеристики'       => $allCharacteristics,
            'Тип'                  => $allCharacteristics['Тип'] ?? null,
            'Страна-изготовитель'  => $allCharacteristics['Страна-изготовитель'] ?? null,
            'Партномер или Артикул производителя' => $allCharacteristics['Партномер (артикул производителя)'] ?? $allCharacteristics['Артикул'] ?? null,
        ];
    }

    public function getPageContent($url)
    {
        $ch = curl_init();

        $cookie_file = sys_get_temp_dir() . '/ozon_cookies_' . uniqid() . '.txt';
        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
            'Accept-Encoding: gzip, deflate, br',
            'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
            'Cache-Control: max-age=0',
            'Connection: keep-alive',
            'Upgrade-Insecure-Requests: 1',
            'Referer: https://www.ozon.ru/',
        ];

        curl_setopt_array($ch, [
            // CURLOPT_PROXY        => '198.44.190.229',
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_COOKIEJAR      => $cookie_file,
            CURLOPT_COOKIEFILE     => $cookie_file,
            CURLOPT_HEADER         => false,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $html = curl_exec($ch);

        if ($html === false) {
            echo "cURL Error (" . curl_errno($ch) . "): " . curl_error($ch) . "\n";
        }
        curl_close($ch);
        if (file_exists($cookie_file)) {
            unlink($cookie_file);
        }
        return $html;
    }

    private function parseTitleWithXPath($xpath)
    {
        $nodes = $xpath->query('//h1[@data-widget="webProductTitle"] | //h1');
        if ($nodes->length > 0) {
            return trim($nodes->item(0)->textContent);
        }
        return null;
    }

    private function parseCategoryWithXPath($xpath)
    {
        $categories = [];
        $nodes = $xpath->query('//ol[contains(@class, "tsBodyControl400Small")]//a/span');

        foreach ($nodes as $node) {
            $category = trim($node->textContent);
            if ($category) {
                $categories[] = $category;
            }
        }
        return $categories ? implode('/', $categories) : null;
    }

    private function parseImagesWithXPath($xpath)
    {
        $images = [];
        $nodes = $xpath->query('//div[contains(@class, "pdp_r1a")]//img | //div[contains(@class, "pdp_r4a")]//img');

        foreach ($nodes as $node) {
            $src = $node->getAttribute('src');
            if ($src) {
                $highResSrc = preg_replace('/\/wc\d+\//', '/wc1000/', $src);
                if (!in_array($highResSrc, $images)) {
                    $images[] = $highResSrc;
                }
            }
        }
        return $images;
    }

    private function parseDescriptionWithXPath($xpath)
    {
        $nodes = $xpath->query('//div[@id="section-description"]');
        if ($nodes->length > 0) {
            return trim($nodes->item(0)->textContent);
        }
        return null;
    }

    private function parseCharacteristicsWithXPath($xpath)
    {
        $characteristics = [];
        $nodes = $xpath->query('//div[@id="section-characteristics"]//dl');

        foreach ($nodes as $node) {
            $keyNode = $xpath->query('.//dt', $node);
            $valueNode = $xpath->query('.//dd', $node);

            if ($keyNode->length > 0 && $valueNode->length > 0) {
                $key = trim($keyNode->item(0)->textContent);
                $value = trim($valueNode->item(0)->textContent);

                if ($key && $value) {
                    $characteristics[$key] = $value;
                }
            }
        }
        return $characteristics;
    }
}

// Пример использования:
// $parser = new OzonDomParser();
// $sku = '727818702'; // Пример SKU
// try {
//     $data = $parser->parseBySku($sku);
//     print_r($data);
// } catch (Exception $e) {
//     echo 'Ошибка: ',  $e->getMessage(), "\n";
// }

// Включаем отображение всех ошибок для удобной отладки
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- Код для запуска парсера ---

$parser = new OzonDomParser();
$sku = '2102536131'; // <-- Можете подставить любой другой SKU для теста

echo "--- Начинаю парсинг товара с OZON SKU: {$sku} ---" . PHP_EOL;

try {
    $data = $parser->parseBySku($sku);

    echo '<pre>';
    print_r($data);
    echo '</pre>';
} catch (Exception $e) {
    echo 'Произошла ошибка: ',  $e->getMessage(), "\n";
}
