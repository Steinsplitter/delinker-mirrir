<?PHP

# Wikipedia bot components
require_once ( __DIR__.'/vendor/mediawiki-api/vendor/autoload.php' ) ;

# "the usual" tools routines, mostly database opening ones; these should probably be replaced by local ones!
require_once ( __DIR__.'/public_html/php/common.php' ) ;


class CommonsDelinquent {

	var $debugging = false ;
	
	function __construct () {
		$this->config = parse_ini_file ( __DIR__.'/bot.cnf' ) ;
	}

	// Runs a MySQL query. Optional debugging output, and output of query on error
	function runQuery ( $db , $sql ) {
		if ( $this->debugging ) print "$sql\n" ;
		if(!$result = $db->query($sql)) die('There was an error running the query [' . $db->error . ']:'."\n$sql\n");
		return $result ;
	}
	
	function getCommonsDB () {
		$this->last_db = openDB ( 'commons' , 'wikimedia' ) ;
		return $this->last_db ;
	}
	
	function getToolDB () {
		$this->last_db = openToolDB ( 'commonsdelinquent_p' ) ;
		return $this->last_db ;
	}
	
	function getDBsafe ( $s ) {
		if ( !isset ( $this->last_db ) ) die ( "getDBsafe called before database was opened!" ) ;
		return $this->last_db->real_escape_string ( $s ) ;
	}
	
	function hasLocalFile ( $wiki , $file ) {
		$ret = false ;
		$this->last_db = openDBwiki ( $wiki ) ;
		$sql = "SELECT * FROM image WHERE img_name='" . $this->getDBsafe($file) . "' LIMIT 1" ;
		$result = $this->runQuery ( $this->last_db , $sql ) ;
		while($o = $result->fetch_object()) $ret = true ;
		return $ret ;
	}
	
	function setDone ( $id , $value , $meta ) {
		$db = $this->getToolDB() ;
		$sql = "UPDATE event SET done=" . ($value*1) ;
		if ( isset ( $meta ) ) {
			if ( !is_array ( $meta ) ) $meta = array ( 'note' => $meta ) ;
			foreach ( $meta AS $k => $v ) {
				$sql .= ",$k='" . $this->getDBsafe($v) . "'" ;
			}
		}
		$sql .= " WHERE id=" . ($id*1) ;
		if ( $value != 1 ) print "$sql\n" ;
		$this->runQuery ( $db , $sql ) ;
		$db->close() ;
	}
	
	function wiki2server ( $wiki ) {
		if ( $wiki == 'wikidatawiki' ) return 'www.wikidata.org' ;
		if ( $wiki == 'commonswiki' ) return 'commons.wikimedia.org' ;
		if ( $wiki == 'mediawikiwiki' ) return 'www.mediawiki.org' ;
		if ( $wiki == 'metawiki' ) return 'meta.wikimedia.org' ;
		if ( $wiki == 'incubatorwiki' ) return 'incubator.wikimedia.org' ;
		if ( preg_match ( '/^(.+?)(wik.+)$/' , $wiki , $m ) ) {
			$server = str_replace('_','-',$m[1]) . "." ;
			if ( $m[2] == 'wiki' ) $server .= 'wikipedia' ;
			else $server .= $m[2] ;
			$server .= '.org' ;
			return $server ;
		}
		return false ;
	}
	
	function getAPI ( $wiki ) {
		# TODO check if re-opening same API, cache in object
		$server = $this->wiki2server ( $wiki ) ;
		if ( $server === false ) return false ;
		$api = new \Mediawiki\Api\MediawikiApi( 'https://'.$server.'/w/api.php' );
		if ( !$api->isLoggedin() ) {
			$x = $api->login( new \Mediawiki\Api\ApiUser( $this->config['name'], $this->config['password'] ) );
			if ( !$x ) return false ;
		}
		return $api ;
	}
	
	function editWiki ( $wiki , $action , $params ) {
		$api = $this->getAPI ( $wiki ) ;
		if ( $api === false ) return false ;
		$params['token'] = $api->getToken() ;
		$x = false ;
		try {
			$x = $api->postRequest( new \Mediawiki\Api\SimpleRequest( $action, $params ) );
		} catch (Exception $e) {
			echo 'Caught exception: ',  $e->getMessage(), "\n";
			$this->last_exception = $e->getMessage() ;
			$x = false ;
		}
		$api->logout() ;
		return $x ;
	}

	function editWikidata ( $action , $params ) {
		$ret = $this->editWiki ( 'wikidatawiki' , $action , $params ) ;
		if ( is_array($ret) ) return $x['success'] ;
		return false ;
	}
		
}

?>