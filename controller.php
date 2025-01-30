<?php
header('Access-Control-Allow-Origin: *');


require_once('in_hook/crest.php');
require_once('sms.ru.php');

session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (isset($_POST['phone'])) {
		$phone = trim(str_replace(array('+', ' '), '', $_POST['phone']));
		$contactData['msg'] = 'Телефон: ' . $phone;
		$resultContactID = CRest::call(
			'crm.duplicate.findbycomm',
			array(
				'entity_type' => "CONTACT",
				'type' => "PHONE",
				'values' => array($phone)
			)
		);


		if (empty($resultContactID['result']['CONTACT'])) {
			$error = $contactData['msg'] .= ' Личный кабинет не зарегистрирован по этому номеру телефона.';
			http_response_code(403);

		} elseif (count($resultContactID['result']['CONTACT']) > 1) {
			$error = $contactData['msg'] .= ' Внимание. С этим номером телефона зарегистрировано ' . count($resultContactID['result']['CONTACT']) . ' контактов!';
			http_response_code(405);

		} else {

			$contactData['ID'] = $resultContactID['result']['CONTACT'][0];
			$message = generateRandomCode();
			$resultContactID = CRest::call(
				'crm.duplicate.findbycomm',
				array(
					'entity_type' => "CONTACT",
					'type' => "PHONE",
					'values' => array($phone)
				)
			);
			$data1 = array(
				'apiKey' => '****',
				'sms' => array(
					array(
						'channel' => 'char',
						'phone' => $phone,
						'sender' => 'OOO_FCB'
					)
				)
			);

			$ch = curl_init('****');
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data1));
			curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));

			$response = curl_exec($ch);

			if ($response === false) {
				echo 'Curl error: ' . curl_error($ch);
			} else {
				echo 'OK ' . 'Code = ' . $response;
			}


			$result = json_decode($response);

			$msg = '';
			// Сохраняем код в переменной сессии
			$_SESSION['verification_code'] = $message;
			//echo "Saved verification code: " . $_SESSION['verification_code'];
			$contactData['sendt_to'] = $contactData['ID'];
			$contactData['phone'] = $phone;
			$contactData['msg'] = $msg;
			$dataToSave = [
				'code' => $message,
				'contactData' => $contactData,
			];
			file_put_contents('temp_code.txt', json_encode($dataToSave));


			//echo json_encode(['status' => 'success', 'code' => $message, 'sendt_to' => $contactData['ID'], 'phone' => $phone, 'msg'=>$msg]);



			curl_close($ch);
			exit();



		}




		echo json_encode(['error' => $error]);
	}




}


// Функция генерации случайного кода
function generateRandomCode()
{
	return mt_rand(100000, 999999);
}





$yookassaData = [
	'bfl' => [
		'description' => 'Договор БФЛ: ',
		'shop' => '219998'


	],
	'agent' => [
		'description' => 'Договор Агентский: ',
		'shop' => '226581'
	]

];






if (isset($_POST['email'])) {


	$email = trim($_POST['email']);

	// Проверяем, есть ли у контакта указанный email
	$resultContactID = CRest::call(
		'crm.duplicate.findbycomm',
		array(
			'entity_type' => "CONTACT",
			'type' => "EMAIL",
			'values' => array($email),
		)
	);

	$contactData = array();

	if (empty($resultContactID['result']['CONTACT'])) {

		$contactData['msg'] = 'Email: <b>' . $email . '</b> успешно сохранен.';
		$resultUpdateContact = CRest::call(
			'crm.contact.update',
			array(
				'ID' => $_POST['contactID'],
				'fields' => array(
					"EMAIL" => array(
						"0" => array(
							"VALUE_TYPE" => "WORK",
							"VALUE" => $email,
						),
					),
				)
			)
		);
	} else {

		$contactData['msg'] = 'Email уже зарегистрирован: <b>' . $email . '</b>';

		//echo json_encode($contactData, JSON_UNESCAPED_UNICODE);

	}
}


if ($_POST['contactID'] and $_POST['contact']) {

	$params = array(
		'FILTER' => array(
			'ID' => $_POST['contactID'],
		),
		'SELECT' => array('EMAIL')
	);

	$resultContact = CRest::call(
		'crm.contact.list',
		$params
	);


	// записываем данные контакта
	$contactData = $resultContact['result'][0];
	$contactData['ID'] = $_POST['contactID'];
	$_SESSION['contactID'] = $contactData['ID'];

	//поля сделки
//    echo json_encode($contactData,JSON_UNESCAPED_UNICODE);	
//     die;

}



// Чат
if ($_POST['contactID'] and $_POST['chat']) {



	$resultContact = CRest::call(
		'crm.contact.get',
		array('ID' => $_POST['contactID'])
	);

	$result = CRest::call(
		'im.chat.user.add',
		array(
			'DIALOG_ID' => $resultContact['result']['UF_CRM_1693491016'],
			'USERS' => array($resultContact['result']['ASSIGNED_BY_ID']),
			'TITLE' => 'Чат ЛК: ' . $resultContact['result']['LAST_NAME'] . ' ' . $resultContact['result']['NAME'],
		),
		$_REQUEST["auth"]
	);
	/*			$result_dialog = CRest::call('im.dialog.messages.get', Array(
					'DIALOG_ID'=> $resultContact['result']['UF_CRM_1693491016']
				), $_REQUEST["auth"]);

				$message_id = $result_dialog['result']['messages'][0]['id'];

				$result_delete = CRest::call('im.message.delete', Array(
					'MESSAGE_ID' => $message_id,
				), $_REQUEST["auth"]);*/
	//echo json_encode($result_delete);

	// Добавить сообщение в диалог
	if ($_POST['message']) {

		$resultInputMessage = CRest::call(
			'im.message.add',
			array(
				'DIALOG_ID' => $resultContact['result']['UF_CRM_1693491016'],
				'MESSAGE' => $_POST['message']
			)
		);



	}

	// Если чат не создан
	if ($resultContact['result']['UF_CRM_1693491016'] == '') {
		// создать чат + привязать к контакту
		$createChat = CRest::call(
			'im.chat.add',
			array(
				'TYPE' => 'OPEN',
				'TITLE' => 'Чат ЛК: ' . $resultContact['result']['LAST_NAME'] . ' ' . $resultContact['result']['NAME'],
				'USERS' => array($resultContact['result']['ASSIGNED_BY_ID']),
				'ENTITY_TYPE' => 'CRM',
				'ENTITY_ID' => 'CONTACT|' . $_POST['contactID'],

			)
		);

		// Записать в контакт ID чата
		$resultUpdateContact = CRest::call(
			'crm.contact.update',
			array(
				'ID' => $_POST['contactID'],
				'fields' => array(
					'UF_CRM_1693491016' => 'chat' . $createChat['result'],
				)
			)
		);

	} else {



		$batch['cmd'] = [
			0 => 'im.dialog.messages.get?DIALOG_ID=' . $resultContact['result']['UF_CRM_1693491016'] . '&LIMIT=0'
		];

		$resultDialog = CRest::call(
			'batch',
			$batch
		);
		if (isset($resultDialog['result']['result'][0]['messages'])) {
			// Массив для хранения уникальных author_id
			$uniqueAuthorIds = [];

			// Сначала собираем все уникальные author_id
			foreach ($resultDialog['result']['result'][0]['messages'] as $message) {
				$author_id = $message['author_id'];
				if (!in_array($author_id, $uniqueAuthorIds) && is_numeric($author_id)) {
					$uniqueAuthorIds[] = $author_id;
				}
			}

			// Получаем данные всех пользователей с уникальными author_id
			$users_get = CRest::call(
				'user.get',
				[
					"ID" => $uniqueAuthorIds
				]
			);

			// Массив для сопоставления author_id -> имя пользователя
			$usersMap = [];

			// Проверяем, что данные пользователей успешно получены
			if (isset($users_get['result']) && is_array($users_get['result'])) {
				foreach ($users_get['result'] as $user) {
					$user_id = $user['ID'];


					if ($user['NAME'] === 'Система') {
						$user_name = 'Вы';
					} else {
						$user_name = $user['NAME'] . ' ' . $user['LAST_NAME'];
					}

					// Добавляем пользователя в карту
					$usersMap[$user_id] = $user_name;
				}
			}

			// Проходим по каждому сообщению и заменяем author_id на имя пользователя
			foreach ($resultDialog['result']['result'][0]['messages'] as $messageKey => $message) {
				$author_id = $message['author_id'];
				$user_name = '';

				// Определяем имя пользователя и тег
				if ($author_id === 'Система CRM ') {
					$user_name = 'Вы';
				} elseif (is_numeric($author_id) && isset($usersMap[$author_id])) {
					$user_name = $usersMap[$author_id];
				} else {
					$user_name = '***';
				}

				// Устанавливаем тег
				if ($user_name === 'Вы') {
					$resultDialog['result']['result'][0]['messages'][$messageKey]['tag'] = 'send_messages';
				} else {
					$resultDialog['result']['result'][0]['messages'][$messageKey]['tag'] = 'received_messages';
				}

				// Обновляем author_id на имя пользователя
				$resultDialog['result']['result'][0]['messages'][$messageKey]['author_id'] = $user_name;

				// Проверяем, что поле 'text' существует и обрабатываем текст
				if (isset($message['text'])) {
					// Убираем содержимое в квадратных скобках в поле text
					$cleanedText = preg_replace('/\[.*?\]/', '', $message['text']);

					// Проверяем, содержит ли текст сообщение, которое нужно заменить
					if (strpos($cleanedText, 'Система CRM пригласил в чат') !== false) {
						// Заменяем текст на сообщение с HTML-ссылкой
						$resultDialog['result']['result'][0]['messages'][$messageKey]['text'] =
							'Добрый день! Мы рады приветствовать Вас в чате компании. Скоро Ваш юрист подключится к диалогу. Пока Вы можете перейти в наш "Чат клиентов " в телеграмм <a href="***" target="_blank">****</a>, где обсуждаются текущие дела клиентов.';
					} else {
						// Обновляем текст сообщения в массиве
						$resultDialog['result']['result'][0]['messages'][$messageKey]['text'] = $cleanedText;
					}
				}
			}
		}

		// Выводим результат
		echo json_encode($resultDialog, JSON_UNESCAPED_UNICODE);
	}


}

// Данные контакта


//$_POST['contactID'] = 108503;
$params = array(
	'FILTER' => array(
		'ID' => $_POST['contactID'],
	),
	'SELECT' => array('ID', 'TITLE', 'PHONE', 'STAGE_ID', 'OPPORTUNITY', 'ASSIGNED_BY_ID', 'DATE_CREATE', 'DATE_MODIFY', 'OPENED', 'UF_CRM_1596391093610', 'UF_CRM_1601928121067')
);

$resultContact = CRest::call(
	'crm.contact.list',
	$params
);


$contactData = $resultContact['result'][0];
$contactData['ID'] = $_POST['contactID'];


//echo json_encode($contactData,JSON_UNESCAPED_UNICODE);			



// Данные по сделкам

if ($_POST['contactID'] and $_POST['deals']) {

	$params = array(
		'FILTER' => array(
			'CONTACT_ID' => $_POST['contactID']
		),
		'SELECT' => array('ID', 'TITLE', 'STAGE_ID', 'OPPORTUNITY', 'ASSIGNED_BY_ID', 'DATE_CREATE', 'DATE_MODIFY', 'OPENED', 'COMMENTS', 'UF_CRM_1596391093610', 'UF_CRM_1601928121067', 'UF_CRM_1706856278')
	);

	$resultDeals = CRest::call(
		'crm.deal.list',
		$params
	);

	echo $resultDeals;

	// Если за контактом закреплены сделки

	// Привязанные сделки - $deal_data array 

	if (isset($resultDeals['total']) and $resultDeals['total'] > 0) {

		//print_r($resultDeals['result']);

		//  Направления сделок - $category_data array

		$resultCategory = CRest::call(
			'crm.category.list',
			array('entityTypeId' => 2) // 2 - сделка
		);

		foreach ($resultCategory['result']['categories'] as $value) {

			$category_data[$value['id']] = $value['name'];

		}
		;

		//  Статусы сделок - $status_data 

		// формирую batch запрос по статусам
		foreach ($resultDeals['result'] as $value) {

			$status_params['cmd'][$value['ID']] = 'crm.status.list?FILTER[STATUS_ID]=' . $value['STAGE_ID'];

		}
		$resultStatus = CRest::call(
			'batch',
			$status_params
		);

		foreach ($resultStatus['result']['result'] as $key => $value) {

			$status_data[$key] = array(
				'STATUS_NAME' => $value[0]['NAME'],
				'CATEGORY_NAME' => $category_data[$value[0]['CATEGORY_ID']]
			);

		}
		//print_r($status_data);

		foreach ($resultDeals['result'] as $key => $value) {

			$contactData['Deals'][$key] = array(

				'ID' => $value['ID'],
				'Название сделки' => $value['TITLE'],
				'Сумма сделки' => $value['OPPORTUNITY'],
				'Ответсвенный' => $value['ASSIGNED_BY_ID'],
				'Дата создания' => $value['DATE_CREATE'],
				'Дата изменения' => $value['DATE_MODIFY'],
				'Статус сделки' => $status_data[$value['ID']]['CATEGORY_NAME'] . ': ' . $status_data[$value['ID']]['STATUS_NAME'],
				'Ссылка на диск' => $value['UF_CRM_1596391093610'],
				'ID корневой папки на диске' => $value['UF_CRM_1601928121067'],
			);

		}
	}

	echo json_encode($contactData, JSON_UNESCAPED_UNICODE);

}

//  Оплата договора: находим № договоров 

if ($_POST['contactID'] and $_POST['payments_agreement']) {
	/*
		 № договора  БФЛ - UF_CRM_5FE8CECE2AAB0
		 № договора АГ - UF_CRM_1694528036
		 Дата создания договоров БФЛ и АГ  - UF_CRM_5FE8CECE3C673
	 */
	$categoryID = 10; // Воронка Продажа

	$params = array(
		'FILTER' => array(
			'CONTACT_ID' => $_POST['contactID'],
			'!=UF_CRM_5FE8CECE3C673' => '', // Дата договора
			'CATEGORY_ID' => $categoryID // Воронка Продажа
		),
		'SELECT' => array('ID', 'TITLE', 'STAGE_ID', 'CATEGORY_ID', 'OPPORTUNITY', 'ASSIGNED_BY_ID', 'DATE_CREATE', 'DATE_MODIFY', 'OPENED', 'COMMENTS', 'UF_CRM_1596391093610', 'UF_CRM_1601928121067', 'UF_CRM_5FE8CECE2AAB0', 'UF_CRM_1694528036', 'UF_CRM_5FE8CECE3C673')
	);

	$resultDeals = CRest::call(
		'crm.deal.list',
		$params
	);


	foreach ($resultDeals['result'] as $deals) {
		if ($deals['UF_CRM_5FE8CECE2AAB0'] != '')
			$payments_agreement[] = $yookassaData['bfl']['description'] . $deals['UF_CRM_5FE8CECE2AAB0'] . ' от ' . $deals['UF_CRM_5FE8CECE3C673'];
		if ($deals['UF_CRM_1694528036'] != '')
			$payments_agreement[] = $yookassaData['agent']['description'] . $deals['UF_CRM_1694528036'] . ' от ' . $deals['UF_CRM_5FE8CECE3C673'];
	}
	//		echo json_encode($payments_agreement,JSON_UNESCAPED_UNICODE);	
	echo json_encode($payments_agreement, JSON_UNESCAPED_UNICODE);


}

// Оплата по договору: формирование и отправка запроса в юкассу

if ($_POST['contactID'] and $_POST['payments'] and $_POST['summ'] and $_POST['description'] and $_POST['email']) {
	//echo json_encode($_REQUEST, JSON_UNESCAPED_UNICODE); die;
	// Тип договора по значению платежа

	if (strpos($_POST['description'], $yookassaData['bfl']['description']) === 0) {

		$type = 'bfl';

	} elseif (strpos($_POST['description'], $yookassaData['agent']['description']) === 0) {

		$type = 'agent';
	}

	//echo json_encode(array('agr'=>$type),JSON_UNESCAPED_UNICODE);	die;

	$ch = curl_init('https://api.yookassa.ru/v3/payments');

	$data = array(
		'amount' => array(
			'value' => $_POST['summ'],
			'currency' => 'RUB',
		),
		'capture' => true,
		'confirmation' => array(
			'type' => 'embedded'
			//'type' => 'redirect',
		),
		'description' => $_POST['description'],
		'receipt' => array(
			'customer' => array(
				'full_name' => $resultContact['result']['NAME'],
				'email' => $_REQUEST['email'],
				//                                'email' => 'maksim32289@mail.ru',
				'phone' => $_POST['phone']
			),
			'type' => 'payment',
			'send' => true,
			'items' => array(
				array(
					'description' => $_POST['description'] . $resultContact['result'][0]['NAME'],
					'quantity' => 1,
					'amount' => array(
						'value' => $_POST['summ'],
						'currency' => 'RUB',
					),
					'vat_code' => '1',
					'payment_mode' => 'full_prepayment',
					'payment_subject' => 'commodity'
				),
			),

			//		'metadata' => array(
//	 		'order_id' => 1,
		)
	);

	$data = json_encode($data, JSON_UNESCAPED_UNICODE);
	//echo $data; die;

	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_HEADER, false);
	curl_setopt($ch, CURLOPT_USERPWD, $yookassaData[$type]['shop'] . ':' . $yookassaData[$type]['password']);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Idempotence-Key: ' . gen_uuid()));
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
	$res = curl_exec($ch);
	curl_close($ch);

	echo $res;
	//echo $res = json_decode($res, true);

}

// Документы

if ($_POST['contactID'] and $_POST['documents']) {

	$params = array(
		'FILTER' => array(
			'CONTACT_ID' => $_POST['contactID']
		),
		'SELECT' => array('ID', 'TITLE', 'UF_CRM_1601928121067', 'UF_CRM_1596391093610')
	);

	$resultDeals = CRest::call(
		'crm.deal.list',
		$params
	);


	// Выводим результат
	//echo json_encode($dealFields['result'], JSON_UNESCAPED_UNICODE);


	// UF_CRM_1601928121067 - корневой каталог пользователя структуры папок на диске (ID папки клиента) 10341909

	foreach ($resultDeals['result'] as $value) {
		if (!empty($value['UF_CRM_1601928121067'])) {
			$folderID = $value['UF_CRM_1601928121067'];
		}
	}

	if (empty($folderID) or $folderID == null) {

		echo json_encode(['folderRoot' => false], JSON_UNESCAPED_UNICODE);
		http_response_code(404);
		die;

		// Если создана структура папок и в сделке есть ID папки клиента UF_CRM_1601928121067

	} else {

		$folderUploadName = 'Документы клиента';

		$params = [
			'id' => $folderID
		];

		$resultFolder = CRest::call(

			'disk.folder.getchildren',
			$params

		);

		foreach ($resultFolder['result'] as $folderProp) {

			if ($folderProp['NAME'] == $folderUploadName)

				$folderUploadId = $folderProp['ID'];

		}

		// если папка "Документы клиента" не создана, создаем ее

		if ($folderUploadId == '' or empty($folderUploadId)) {

			$params = [
				'id' => $folderID,
				'data' => [
					'NAME' => $folderUploadName
				]

			];

			$resultFolderUpload = CRest::call(
				'disk.folder.addsubfolder',
				$params
			);
		}

		/* загружаем файлы */

		if ($_POST['upload'] and isset($_FILES)) {
			function getUniqueFileName($folderId, $fileName)
			{
				// Получаем список файлов в папке
				$result = CRest::call('disk.folder.getchildren', array(
					'id' => $folderId,
					'filter' => array('NAME' => $fileName),
				));

				if ($result['result'] && !empty($result['result'])) {
					$baseName = pathinfo($fileName, PATHINFO_FILENAME);
					$extension = pathinfo($fileName, PATHINFO_EXTENSION);
					$counter = 1;

					// Формируем новое имя файла, пока не будет найдено уникальное
					do {
						$newFileName = $baseName . '_' . $counter . '.' . $extension;
						$result = CRest::call('disk.folder.getchildren', array(
							'id' => $folderId,
							'filter' => array('NAME' => $newFileName),
						));
						$counter++;
					} while (!empty($result['result']));

					return $newFileName;
				}
				return $fileName;
			}

			//		echo json_encode($resultDeals,JSON_UNESCAPED_UNICODE); die;				

			foreach ($_FILES as $key => $value) {

				if (0 < $value['error']) {
					echo 'Error: ' . $value['error'] . '<br>';
				} else {

					$newfile = base64_encode(file_get_contents($value['tmp_name']));
					$uniqueFileName = getUniqueFileName($folderUploadId, $value['name']);

					$resultUpload = CRest::call(
						'disk.folder.uploadfile',
						array(
							'id' => $folderUploadId,
							'data' => array('NAME' => $uniqueFileName),
							'fileContent' => $newfile,
						)
					);

					$file_name = $uniqueFileName;
					echo json_encode($resultUpload, JSON_UNESCAPED_UNICODE);
					$resultContact = CRest::call(
						'crm.contact.get',
						array('ID' => $_POST['contactID'])
					);
					$contactResponsible = $resultContact['result']['ASSIGNED_BY_ID'];
					foreach ($resultDeals['result'] as $key => $value) {

						$contactData['Deals'][$key] = array(
							'Ссылка на диск' => $value['UF_CRM_1596391093610'],

						);

					}


					$result = CRest::call(
						'im.notify.personal.add',
						array(
							'USER_ID' => $contactResponsible,
							'MESSAGE' => 'Клиент только что добавил файл!' . ' ' . $value['UF_CRM_1596391093610']

						),
						$_REQUEST["auth"]
					);
					//echo json_encode($value['UF_CRM_1596391093610']);
					$message_text = $messageText = "Файл \"$file_name\" успешно добавлен!";
					$messageAdd = CRest::call(
						'im.message.add',
						array(
							'DIALOG_ID' => $resultContact['result']['UF_CRM_1693491016'],
							'MESSAGE' => $messageText,
							'SYSTEM' => 'Y',
						),
						$_REQUEST["auth"]
					);
					echo json_encode($messageAdd, JSON_UNESCAPED_UNICODE);


				}
			}

		}

		/* end загружаем файлы */

		// Файлы и папки в папке "Документы клиента"

		$folders['cmd'][] = 'disk.folder.getchildren?id=' . $folderUploadId;

		$result = CRest::call(
			'batch',
			$folders
		);


		$sum = array_sum($result['result']['result_total']);
		//echo $sum.'<br/>'; 
		$files = array();


		while ($sum > 0) {
			// формируем массив подпапок и файлов по результату запроса
			$data = folders_files($result);
			// Есть файлы в папке
			if (isset($data['files']))
				$files = array_merge($files, $data['files']);
			// Нет подпапок
			if (empty($data['folders'])) {
				break;
			}
			// запрос данных из папок
			$result = CRest::call(
				'batch',
				$data['folders']
			);
			// есть ли данные по запросу
			$sum = (isset($result['result']['result_total'])) ? array_sum($result['result']['result_total']) : 0;
			unset($data);
		}

	}

	echo json_encode($files, JSON_UNESCAPED_UNICODE);

}

// уникальное значение операции на стороне сайта для Юкасса
function gen_uuid()
{
	return sprintf(
		'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
		mt_rand(0, 0xffff),
		mt_rand(0, 0xffff),
		mt_rand(0, 0xffff),
		mt_rand(0, 0x0fff) | 0x4000,
		mt_rand(0, 0x3fff) | 0x8000,
		mt_rand(0, 0xffff),
		mt_rand(0, 0xffff),
		mt_rand(0, 0xffff)
	);
}

function folders_files($result)
{
	//print_r($result);
	foreach ($result['result']['result'] as $key => $parent_value) {
		foreach ($parent_value as $value) {
			//print_r($value);
			if ($value['TYPE'] == 'folder')
				$outData['folders']['cmd'][] = 'disk.folder.getchildren?id=' . $value['ID'];
			elseif ($value['TYPE'] == 'file')
				$outData['files'][] = array(
					'NAME' => $value['NAME'],
					'DOWNLOAD_URL' => $value['DOWNLOAD_URL'],
					'DETAIL_URL' => $value['DETAIL_URL']
				);
		}
	}
	//print_r($outData);

	return $outData;
}


