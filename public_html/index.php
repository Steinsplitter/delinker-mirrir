<?PHP

error_reporting(E_ERROR|E_CORE_ERROR|E_ALL|E_COMPILE_ERROR);
ini_set('display_errors', 'On');

require_once ( '../shared.inc' ) ;

$status = array (
	0 => "<span style='color:black'>Pending</span>" ,
	1 => "<span style='color:green'>Done</span>" ,
	2 => "<span style='color:red'>Issue</span>"
) ;

print get_common_header ( '' , 'Commons Delinquent' ) ;

print "<div class='lead'>The is a rewrite of <a href='/delinker'>CommonsDelinker</a>.</div>" ;

$mode = get_request ( 'mode' , 'latest' ) ;

$cd = new CommonsDelinquent() ;
$db = $cd->getToolDB() ;

if ( $mode == 'latest' ) {
	$num = get_request ( 'num' , 100 ) * 1 ;
	$offset = get_request ( 'offset' , 0 ) * 1 ;
	$sql = "SELECT * FROM event ORDER BY timestamp DESC,log_timestamp DESC limit $num offset $offset" ;
#	print "<pre>$sql</pre>" ;
	$result = $cd->runQuery ( $db , $sql ) ;
	print "<table class='table table-condensed table-striped'>" ;
	print "<thead><th>Time</th><th>File</th><th>Page</th><th>Status</th></thead>" ;
	print "<tbody style='font-size:9pt'>" ;
	while($o = $result->fetch_object()) {
		print "<tr>" ;
		print "<td nowrap>" . substr($o->timestamp,0,4).':'.substr($o->timestamp,4,2).':'.substr($o->timestamp,6,2).'&nbsp;'.substr($o->timestamp,8,2).':'.substr($o->timestamp,10,2).':'.substr($o->timestamp,12,2) . "</td>" ;
		print "<td><a target='_blank' href='//commons.wikimedia.org/wiki/File:" . htmlspecialchars($o->file) . "'>" . str_replace('_',' ',$o->file) . "</a></td>" ;

		print "<td><a target='_blank' href='//" . $cd->wiki2server($o->wiki) . "'>" . $o->wiki . "</a>:" ;
		print "<a target='_blank' href='//" . $cd->wiki2server($o->wiki) . "/wiki/" . htmlspecialchars($o->page) . "'>" . str_replace('_',' ',$o->page) . "</a></td>" ;

		print "<td>" . $status[$o->done] ;
		if ( $o->note != '' ) print "<br/><small>" . $o->note . "</small>" ;
		print "</td>" ;
		print "</tr>" ;
	}
	print "</tbody></table>" ;

	print "<div>" ;
	if ( $offset > 0 ) print "<a href='?mode=$mode&num=$num&offset=" . ($offset-$num) . "'>Newer $num</a> | " ;
	print "<a href='?mode=$mode&num=$num&offset=" . ($offset+$num) . "'>Older $num</a>" ;
	print "</div>" ;
	
}

print get_common_footer() ;

?>