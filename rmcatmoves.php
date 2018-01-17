#!/usr/bin/php
<?php
/*
Description: Script removeds fulfilled category move requests from COM:CDC.
Cron: jsub -mem 400m -N catdemon -quiet -once /data/project/commons-delinquent/rmcatmoves.php
*/

require_once ( './shared.inc' ) ;

                $api = new \Mediawiki\Api\MediawikiApi( 'https://commons.wikimedia.org/w/api.php' );
                if ( $api ) {
                        $services = new \Mediawiki\Api\MediawikiFactory( $api );
                        $page = $services->newPageGetter()->getFromTitle( 'User:CommonsDelinker/commands' );
                        $revision = $page->getRevisions()->getLatest();

                        if ( $revision ) {
                                $ret = $revision->getContent()->getData() ;
                        }
                }

$tools_pw = posix_getpwuid ( posix_getuid () );
$tools_mycnf = parse_ini_file( $tools_pw['dir'] . "/replica.my.cnf" );
$db = new mysqli( 'commonswiki.labsdb', $tools_mycnf['user'], $tools_mycnf['password'], 'commonswiki_p' );
// Check replications lag
if ( $db->connect_errno )
        die( "Failed to connect to labsdb: (" . $db->connect_errno . ") " . $db->connect_error );

$replag = $db->query( "SELECT lag FROM heartbeat_p.heartbeat WHERE shard = 's4';" )->fetch_object()->lag;
$replagmax = "15";

if ($replag > $replagmax ) {
  echo "Replication lag (bigger than 5 seconds) detected. Switching off script.\n";
  exit();
}
else {
  echo "Replication lag is OK (below 5 seconds), continuing...\n";
}

if (preg_match('/\{\{[Ss]top(\|catmove|)\}\}/', $ret ))
    {
    echo "STOPPED... CONTAINS *STOP* TEMPLATE";
    die();
    }
// Check cat move commands
$raw  = "";
foreach(preg_split('~[\r\n]+~', $ret) as $line){
    if (preg_match('/\{\{(move_cat|move cat)\|(.*?)\|/i', $line, $matches))
    {
        $cat = $matches[2];
        $incatq = str_replace( " ", "_", $cat );
        $incat = $db->query( 'SELECT cat_pages AS incat FROM category WHERE cat_title = "'. $db->real_escape_string($incatq) .'" LIMIT 1;' )->fetch_object()->incat;

    if($incat == "0") {
    // do nothing
    } elseif ( !is_numeric($incat) )  {
    // do nothing
    }
    else {
      $raw .= $line."\r\n";
    }

    } else {
        $raw .= $line."\r\n";
    }
}
unset($tools_mycnf, $tools_pw);

// edit COM:CDC

$params = array (
        'title' => "User:CommonsDelinker/commands" ,
        'text' => trim($raw) ,
        'summary' => "Removing completed category move commands." ,
        'bot' => 1
) ;

if ( !$api->isLoggedin() ) {
                        $config = parse_ini_file ( __DIR__.'/bot.cnf' ) ;
                        $x = $api->login( new \Mediawiki\Api\ApiUser( $config['name'], $config['password'] ) );
                        if ( !$x ) return false ;
                }

$params['token'] = $api->getToken() ;
$params['bot'] = 1 ;

echo "Saving page...";
$x = $api->postRequest( new \Mediawiki\Api\SimpleRequest( "edit", $params ) );

var_dump($x);

$api->logout() ;
?>
