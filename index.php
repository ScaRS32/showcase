<?php

define("EXTRANET_NO_REDIRECT", true);
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);

use Bitrix\Main\Loader;
use Bitrix\Disk\Folder;
use Bitrix\Disk\File;
use Bitrix\Main\Diag\Debug;
use Bitrix\Main\Config\Option;
use Bitrix\Crm\Service\Container;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Internals\ExternalLinkTable;

require_once $_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php";

if (!Loader::includeModule('crm')) {
    sendJsonError("Не удалось загрузить модули", 500);
}

$data = json_decode(file_get_contents("php://input"), true);


$headers = apache_request_headers();
$authHeader = $headers['authorization'] ?? '';

if (!checkApiToken($authHeader)) {
    sendJsonError("Invalid API token", 403);
}

$action = $data['action'] ?? '';

switch ($action) {
    case 'getOferta':
        handleGetOferta($data);
        break;

    case 'getContractsForPayment':
        handleGetContractsForPayment($data);
        break;

    case 'getDocuments':
        handleGetDocuments($data);
        break;

    case 'uploadDocument':
        handleUploadDocument($data);
        break;

    default:
        sendJson(["error" => "Неизвестное действие"]);
}


/**
 * Обработка получения ссылки на Оферту
 *
 * @param array $data
 * @return void
 */
function handleGetOferta(array $data): void
{
    $contactId = (int) ($data['contact_id'] ?? 0);
    $type = $data['type'] ?? '';

    if ($contactId <= 0 || empty($type)) {
        sendJsonError("Некорректные данные для запроса оферты", 400);
    }

    // Уникальный ключ для кеширования
    $cacheKey = "oferta_{$contactId}_{$type}";
    $cacheDir = __DIR__ . '/cache/';
    $cacheFile = $cacheDir . md5($cacheKey) . '.json';
    clearCache($cacheDir);

    // Проверяем, существует ли кеш и актуален ли он (время жизни кеша — 1 час)
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 3600)) {
        $cachedData = file_get_contents($cacheFile);
        sendJson(json_decode($cachedData, true));
        return;
    }

    if ($type === 'BFL') {
        $ufField = 'UF_CRM_5F9697C0C7CDF';
        $fileField = 'UF_CRM_66212BC460584';
    } else {
        $ufField = 'UF_CRM_1709290790';
        $fileField = 'UF_CRM_66212BDB6C669';
    }

    $entityTypeId = \CCrmOwnerType::Deal;
    $container = Container::getInstance();
    $factory = $container->getFactory($entityTypeId);

    if (!$factory) {
        sendJsonError("Фабрика сделок недоступна", 500);
    }

    $items = $factory->getItems([
        'filter' => ['=CONTACT_ID' => $contactId],
    ]);

    if (empty($items)) {
        http_response_code(421);
        echo json_encode(["error" => "В данный момент скачивание договора находится в разработке"], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Первая найденная сделка
    $deal = $items[0];
    $dealData = $deal->getData();

    if (!isset($dealData[$ufField])) {
        http_response_code(421);
        echo json_encode(["error" => "В данный момент скачивание договора находится в разработке"], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $link = $dealData[$ufField];

    if (strpos($link, '***') === false) {

        $fileId = $dealData[$fileField];
        if (!isset($dealData[$fileField])) {
            http_response_code(421);
            echo json_encode(["error" => "Ссылка на файл договора не установлена, обратитесь к Вашему менеджеру"], JSON_UNESCAPED_UNICODE);
            exit();
        }

        $fileInfo = CFile::GetFileArray($fileId);
        if (!$fileInfo) {
            die("Файл с ID={$fileId} не найден в b_file");
        }


        $absolutePath = $_SERVER["DOCUMENT_ROOT"] . $fileInfo['SRC'];

        $content = file_get_contents($absolutePath);

        if ($content === false) {
            die("Не удалось прочитать файл {$absolutePath}");
        }

        $base64 = base64_encode($content);
        $fileName = $fileInfo['FILE_NAME'];


        $result = ['base64' => $base64, 'fileName' => $fileName];

    }
    // Сохраняем результат в кеш
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0777, true);
    }
    file_put_contents($cacheFile, json_encode($result));

    sendJson($result);

}


/**
 * Обработка получения договоров для оплаты
 *
 * @param array $data
 * @return void
 */
function handleGetContractsForPayment(array $data): void
{
    $contactId = (int) ($data['contact_id'] ?? 0);
    $payments_agreement = $data['payments_agreement'] ?? '';

    if ($contactId <= 0 || $payments_agreement !== '1') {
        sendJsonError("Некорректные данные запроса", 400);
    }

    // Поля:
    // UF_CRM_5FE8CECE2AAB0 - № договора БФЛ
    // UF_CRM_1694528036   - № договора Агентский
    // UF_CRM_5FE8CECE3C673 - Дата создания договоров БФЛ и АГ
    // CATEGORY_ID = 10 (Продажа)

    $yookassaData = [
        'bfl' => [
            'description' => 'Договор БФЛ: '
        ],
        'agent' => [
            'description' => 'Договор Агентский: '
        ]
    ];

    $entityTypeId = \CCrmOwnerType::Deal;
    $container = Container::getInstance();
    $factory = $container->getFactory($entityTypeId);

    if (!$factory) {
        sendJsonError("Фабрика сделок недоступна", 500);
    }

    $items = $factory->getItems([
        'filter' => [
            '=CONTACT_ID' => $contactId,
            '!=UF_CRM_5FE8CECE3C673' => '',
            '=CATEGORY_ID' => 10
        ],
        'select' => [
            'UF_CRM_5FE8CECE2AAB0',
            'UF_CRM_1694528036',
            'UF_CRM_5FE8CECE3C673'
        ]
    ]);

    if (empty($items)) {
        http_response_code(404);
        echo json_encode(['error' => 'Сделки не найдены для указанного контакта.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $payments_agreement_result = [];
    foreach ($items as $deal) {
        $dataDeal = $deal->getData();

        // Договор БФЛ
        if (!empty($dataDeal['UF_CRM_5FE8CECE2AAB0'])) {
            $payments_agreement_result[] = $yookassaData['bfl']['description'] . $dataDeal['UF_CRM_5FE8CECE2AAB0'] . ' от ' . $dataDeal['UF_CRM_5FE8CECE3C673'];
        }

        // Договор Агентский
        if (!empty($dataDeal['UF_CRM_1694528036'])) {
            $payments_agreement_result[] = $yookassaData['agent']['description'] . $dataDeal['UF_CRM_1694528036'] . ' от ' . $dataDeal['UF_CRM_5FE8CECE3C673'];
        }
    }

    $payments_agreement_result = array_unique($payments_agreement_result);

    if (!empty($payments_agreement_result)) {
        sendJson(array_values($payments_agreement_result));
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Нет данных о договорах для указанного контакта.'], JSON_UNESCAPED_UNICODE);
        exit();
    }
}


/**
 * Обработка получения списка файлов документов для указанного контакта.
 *
 * @param array $data
 * @return void
 */
function handleGetDocuments(array $data): void
{
    $contactId = (int) ($data['contact_id'] ?? 0);
    $documentsFlag = $data['documents'] ?? '';

    if ($contactId <= 0 || $documentsFlag !== '1') {
        sendJsonError("Некорректные данные запроса", 400);
    }

    $entityTypeId = \CCrmOwnerType::Deal;
    $container = Container::getInstance();
    $factory = $container->getFactory($entityTypeId);

    if (!$factory) {
        sendJsonError("Фабрика сделок недоступна", 500);
    }

    // Ищем сделки по contactId, CATEGORY_ID = 8
    $items = $factory->getItems([
        'filter' => [
            '=CONTACT_ID' => $contactId,
            '=CATEGORY_ID' => 8
        ],
        'select' => [
            'UF_CRM_1707916303262' // ID корневой папки клиента
        ]
    ]);

    if (empty($items)) {
        http_response_code(404);
        echo json_encode(['error' => 'Нет данных о документах для указанного контакта.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Находим первую сделку, у которой есть UF_CRM_1707916303262
    $folderID = null;
    foreach ($items as $deal) {
        $dataDeal = $deal->getData();
        if (!empty($dataDeal['UF_CRM_1707916303262'])) {
            $folderID = (int) $dataDeal['UF_CRM_1707916303262'] - 1;
            break;
        }
    }

    if (!$folderID) {
        http_response_code(404);
        echo json_encode(['error' => 'Нет корневой папки документов для указанного контакта.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $rootFolder = Folder::loadById($folderID);
    if (!$rootFolder) {
        sendJsonError("Не удалось загрузить корневую папку документов", 500);
    }

    $storage = $rootFolder->getStorage();
    $securityContext = $storage->getFakeSecurityContext();
    $folderUploadName = '01-03-01-01 - Документы клиента ЛК';

    $folderUpload = $dataDeal['UF_CRM_1707916303262'];
    $rootChildren = $rootFolder->getChildren($securityContext);
    foreach ($rootChildren as $child) {
        if ($child instanceof Folder && $child->getName() === $folderUploadName) {
            $folderUpload = $child;
            break;
        }
    }

    if (!$folderUpload) {
        // Создаём подпапку, если не существует
        $folderUpload = $rootFolder->addSubFolder([
            'NAME' => $folderUploadName,
            'CREATED_BY' => 1
        ]);
        if (!$folderUpload) {
            sendJsonError("Не удалось создать папку для документов клиента", 500);
        }
    }

    $files = getAllFilesFromFolder($folderUpload, $securityContext);
    sendJson($files);
}


/**
 * Обработка загрузки файлов документов
 *
 * @param array $data
 * @return void
 */
function handleUploadDocument(array $data): void
{

    $contactId = (int) ($data['contact_id'] ?? 0);
    if ($contactId <= 0) {
        sendJsonError("Некорректные данные для загрузки документа", 400);
    }

    $entityTypeId = \CCrmOwnerType::Deal;
    $container = Container::getInstance();
    $factory = $container->getFactory($entityTypeId);

    if (!$factory) {
        sendJsonError("Фабрика сделок недоступна", 500);
    }

    // Ищем сделки по контактному ID, CATEGORY_ID = 8
    $items = $factory->getItems([
        'filter' => [
            '=CONTACT_ID' => $contactId,
            '=CATEGORY_ID' => 8
        ],
        'select' => [
            'UF_CRM_1707916303262' // ID корневой папки клиента
        ]
    ]);

    if (empty($items)) {
        sendJsonError('Нет данных о документах для указанного контакта.', 404);
    }

    $folderID = null;
    foreach ($items as $deal) {
        $dataDeal = $deal->getData();
        if (!empty($dataDeal['UF_CRM_1707916303262'])) {
            $folderID = (int) $dataDeal['UF_CRM_1707916303262'];
            break;
        }
    }

    if (!$folderID) {
        sendJsonError('Нет корневой папки документов для указанного контакта.', 404);
    }

    $rootFolder = \Bitrix\Disk\Folder::loadById($folderID);
    if (!$rootFolder) {
        sendJsonError("Не удалось загрузить корневую папку документов", 500);
    }

    // Директория для загрузки документов
    $folderUploadName = '01-03-01-01';
    $storage = $rootFolder->getStorage();
    $securityContext = $storage->getFakeSecurityContext();

    $folderUpload = null;
    $children = $rootFolder->getChildren($securityContext);
    foreach ($children as $child) {
        if ($child instanceof \Bitrix\Disk\Folder && $child->getName() === $folderUploadName) {
            $folderUpload = $child;
            break;
        }
    }

    if (!$folderUpload) {
        $folderUpload = $rootFolder->addSubFolder([
            'NAME' => $folderUploadName,
            'CREATED_BY' => 1
        ]);
        if (!$folderUpload) {
            sendJsonError("Не удалось создать папку для документов клиента", 500);
        }
    }

    // Загрузка файлов
    $uploadedFiles = [];
    $filesBase64 = $data['files_base64'] ?? [];
    foreach ($filesBase64 as $fileItem) {
        $fileName = $fileItem['name'] ?? 'noname';
        $fileContentBase64 = $fileItem['content'] ?? '';

        if (empty($fileContentBase64)) {
            continue;
        }

        $fileContent = base64_decode($fileContentBase64);

        $tempPath = $_SERVER['DOCUMENT_ROOT'] . '/upload/tmp_' . md5(uniqid()) . '.tmp';
        file_put_contents($tempPath, $fileContent);

        $pathInfo = pathinfo($fileName);
        $extension = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';
        $uniqueFileName = $pathInfo['filename'] . '_' . uniqid() . $extension;

        $fileArray = \CFile::MakeFileArray($tempPath);
        $fileArray['name'] = $uniqueFileName;

        $folderUpload->uploadFile($fileArray, [
            'CREATED_BY' => 1
        ]);

        unlink($tempPath);
    }

    sendJson($uploadedFiles);
}

/**
 * Возвращает ссылку на скачивания файлов
 *
 * @param File $file
 * @return string
 */
function getDownloadUrl(File $file): string
{
    // Получаем уже существующую публичную ссылку
    $extLink = $file->getExternalLinks()[0] ?? null;

    // Если публичной ссылки нет, создаём её
    if (!$extLink) {
        $extLink = $file->addExternalLink([
            'CREATED_BY' => 35683,
            'TYPE' => ExternalLinkTable::TYPE_MANUAL,
        ]);

        if (!$extLink) {
            return '';
        }
    }

    $hash = $extLink->getHash();
    $urlManager = Driver::getInstance()->getUrlManager();

    // Получаем короткую публичную ссылку
    $extLinkUrl = $urlManager->getShortUrlExternalLink([
        'hash' => $hash,
        'action' => 'download',
    ], true);

    return $extLinkUrl;
}


/**
 * Очистка кеша
 * @param mixed $cacheDir
 * @param mixed $maxFiles
 * @param mixed $maxSize
 * @return void
 */
function clearCache($cacheDir, $maxFiles = 1000, $maxSize = 104857600): void
{
    // 1000 файлов или 100 МБ 
    if (!is_dir($cacheDir)) {
        return;
    }

    $files = glob($cacheDir . '*.json');
    $totalSize = array_sum(array_map('filesize', $files));

    // Если превышен лимит, удаляем самые старые файлы
    if (count($files) > $maxFiles || $totalSize > $maxSize) {
        usort($files, function ($a, $b) {
            return filemtime($a) - filemtime($b);
        });

        foreach ($files as $file) {
            if (count($files) <= $maxFiles && $totalSize <= $maxSize) {
                break;
            }

            unlink($file);
            $files = array_diff($files, [$file]);
            $totalSize -= filesize($file);
        }
    }
}


/**
 * Рекурсивное получение всех файлов из директории
 *
 * @param Folder $folder
 * @param $securityContext
 * @return array
 */
function getAllFilesFromFolder(Folder $folder, $securityContext): array
{
    $itemsArray = [];
    $children = $folder->getChildren($securityContext);

    foreach ($children as $child) {
        if ($child instanceof File) {
            $itemsArray[] = [
                'NAME' => $child->getName(),
                'DOWNLOAD_URL' => getDownloadUrl($child),
                'DETAIL_URL' => getDetailUrl($child)
            ];
        } elseif ($child instanceof Folder) {
            $subFiles = getAllFilesFromFolder($child, $securityContext);
            $itemsArray = array_merge($itemsArray, $subFiles);
        }
    }

    return $itemsArray;
}


/**
 * Возвращает detail URL файла
 *
 * @param File $file
 * @return string
 */
function getDetailUrl(File $file): string
{
    $urlManager = \Bitrix\Disk\Driver::getInstance()->getUrlManager();
    return $urlManager->getPathFileDetail($file);
}


/**
 * @param $token
 * @return bool
 */
function checkApiToken($token): bool
{
    if (stripos($token, 'Bearer ') !== 0) {
        return false;
    }
    $token = substr($token, 7);
    $validToken = Option::get("FCB", "ApiReferralProgramSecret");
    return $token === $validToken;
}


/**
 * @param $data
 * @param int $httpCode
 * @return void
 */
function sendJson($data, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * @param string $message
 * @param int $httpCode
 * @return void
 */
function sendJsonError(string $message, int $httpCode = 400): void
{
    sendJson(["error" => $message], $httpCode);
}
