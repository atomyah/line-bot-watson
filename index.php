<?php
require_once __DIR__ .'/vendor/autoload.php';
require __DIR__ . '/functions.php';

$httpClient = new \LINE\LINEBot\HTTPClient\CurlHTTPClient(getenv('CHANNEL_ACCESS_TOKEN'));

$bot = new \LINE\LINEBot($httpClient, ['channelSecret' => getenv('CHANNEL_SECRET')]);

$signature = $_SERVER['HTTP_' . \LINE\LINEBot\Constant\HTTPHeader::LINE_SIGNATURE];

try {
  $events = $bot->parseEventRequest(file_get_contents('php://input'), $signature);
} catch (\LINE\LINEBot\Exception\InvalidSignatureException $e) {
  error_log('ParseEventRequest failed. InvalidSignatureException => '. var_export($e, TRUE));
} catch (\LINE\LINEBot\Exception\UnknownEventTypeException $e) {
  error_log('ParseEventRequest failed. UnknownEventTypeException => '. var_export($e, TRUE));
} catch (\LINE\LINEBot\Exception\UnknownMessageTypeException $e) {
  error_log('ParseEventRequest failed. UnknownMessageTypeException => '. var_export($e, TRUE));
} catch (\LINE\LINEBot\Exception\InvalidEventRequestException $e) {
  error_log('ParseEventRequest failed. InvalidEventRequestException => '. var_export($e, TRUE));
}


foreach ($events as $event) {
  if (!($event instanceof \LINE\LINEBot\Event\MessageEvent)) {
    error_log('not message event has come');
    continue;
  }
  if (!($event instanceof \LINE\LINEBot\Event\MessageEvent\TextMessage)) {
    error_log('not text message has come');
    continue;
  }

  define('TABLE_NAME_CONVERSATIONS', 'conversations');
  
  $data = array('input' => array('text' =>  $event->getText()));
  
  // 前回までの会話がデータベースに保存されていれば
  if(getLastConversationData($event->getUserId()) !== PDO::PARAM_NULL) {
    $lastConversationData = getLastConversationData($event->getUserId());
    
    // 前回までの会話のデータをパラメータに追加
    $data['context'] = array('conversation_id' => $lastConversationData['conversation_id'], 
        'system' => array('dialog_stack' => array(array('dialog_node' => $lastConversationData['dialog_node'])),
        'dialog_turn_counter' => 1,
        'dialog_request_counter' => 1
        ));
  }
  
  // ConversationサービスのREST API
  $url = 'https://gateway.watsonplatform.net/conversation/api/v1/workspaces/' . getenv('WATSON_WORKSPACE_ID') . '/message?version=2016-09-20';

  // 新規セッションを初期化
  $curl = curl_init($url);
  
  // オプション
  $options = array(
      // コンテンツタイプ
      CURLOPT_HTTPHEADER => array('Content-Type: application/json',),
      // 証明用
      CURLOPT_USERPWD => getenv('WATSON_USERNAME') . ':' . getenv('WATSON_PASSWORD'),
      // POST
      CURLOPT_POST => TRUE,
      // 内容
      CURLOPT_POSTFIELDS => json_encode($data),   // エンコードするとJSON形式平文になる
      // curl_exec時にbooleanでなく取得結果を返す
      CURLOPT_RETURNTRANSFER => TRUE,
  );
  
  // オプションを適用
  curl_setopt_array($curl, $options);
  // セッションを実行し結果を取得
  $jsonString = curl_exec($curl);
  //文字列を連想配列に変換
  $json = json_decode($jasonString, TRUE);        // デコードすると連想配列になる
  
  // 会話データを取得
  $conversationId = $json['context']['conversation_id'];
  $dialogNode= $json['context']['system']['dialog_stack'][0]['dialog_node'];
  
  // データベースに保存
  $conversationData = array('conversation_id' => $conversationId, 'dialog_node' => $dialogNode);
  setLastConversationData($event->getUserId(), $conversationData);
  
  // Conversationからの返答を取得
  $outputText = $json['output']['text'][count($json['output']['text']) - 1];
  
  //ユーザーに返信
  replyTextMessage($bot, $event->getReplyToken(), $outputText);
  
}


// 会話データをデータベースに保存
function setLastConversationData($userId, $lastConversationData) {
  $conversationId = $lastConversationData['conversation_id'];
  $dialogNode = $lastConversationData['dialog_node'];
  
  if(getLastConversationData($userId) === PDO::PARAM_NULL) {
    $dbh = dbConnection::getConnection();
    $sql = 'insert into ' . TABLE_NAME_CONVERSATIONS . ' (conversation_id, dialog_node, userid) values (?, ?, pgp_sym_encrypt(?, \'' . getenv('DB_ENCRYPT_PASS') . '\'))';
    $sth = $dbh->prepare($sql);
    $sth->execute(array($conversationId, $dialogNode, $userId));
  } else {
    $dbh = dbConnection::getConnection();
    $sql = 'update ' . TABLE_NAME_CONVERSATIONS . ' set conversation_id = ?, dialog_node = ? where ? = pgp_sym_decrypt(userid, \'' . getenv('DB_ENCRYPT_PASS') . '\')';
    $sth = $dbh->prepare($sql);
    $sth->execute(array($conversationId, $dialogNode, $userId));
  }
}


// データベースから会話データを取得
function getLastConversationData($userId) {
  $dbh = dbConnection::getConnection();
  $sql ='select conversation_id, dialog_node from ' . TABLE_NAME_CONVERSATIONS . ' where ? = pgp_sym_decrypt(userid, \'' . getenv('DB_ENCRYPT_PASS') . '\')';
  $sth = $dbh->prepare($sql);
  $sth->execute(array($userId));
  if (!($row = $sth->fetch())) {
    return PDO::PARAM_NULL;
  } else {
    return array('conversation_id' => $row['conversation_id'], 'dialog_node' => $row['dialog_id']);
  }
}


// データベースへの接続を管理するクラス
class dbConnection {
  // インスタンス
  protected static $db;
  // コンストラクタ
  private function __construct() {
    
    try {
      // 環境変数からデータベースへの接続情報を取得し
      $url = parse_url(getenv('DATABASE_URL'));
      // データソース
      $dsn = sprintf('pgsql:host=%s;dbname=%s', $url['host'], substr($url['path'], 1));
      // 接続を確立
      self::$db = new PDO($dsn, $url['user'], $url['pass']);
      // エラー時例外を投げるように設定
      self::$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (Exception $ex) {
      echo 'Connection Error: ' . $ex->getMessage();
    }
  }
  
  // シングルトン
  public static function getConnection() {
    if (!self::$db) {
      new dbConnection();
    }
    return self::$db;
  }
}

?>
