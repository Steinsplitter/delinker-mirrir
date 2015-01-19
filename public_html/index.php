<?PHP

error_reporting(E_ERROR|E_CORE_ERROR|E_COMPILE_ERROR); // E_ALL|
ini_set('display_errors', 'On');

require_once ( '../shared.inc' ) ;

$image = trim ( get_request ( 'image' , '' ) ) ;
$action = get_request ( 'action' , 'any' ) ;
$result = get_request ( 'result' , 'any' ) ;

function esc ( $s ) {
	return str_replace ( '"' , '&quot' , str_replace ( "'" , '&#39;' , $s ) ) ;
}

$status = array (
	0 => "<span style='color:black'>Pending</span>" ,
	1 => "<span style='color:green'>Done</span>" ,
	2 => "<span style='color:red'>Skipped</span>"
) ;

print get_common_header ( '' , 'Commons Delinquent' ) ;

print "<div class='lead'>The is a rewrite of <a href='/delinker'>CommonsDelinker</a>.</div>
<div><form method='get'>
<table class='table'>
<tbody>
<tr><th>File name</th><td><input type='text' name='image' style='width:100%' value='" . esc($image) . "' /></td></tr>
<tr><th>Action</th><td><select name='action'>
<option value='any' " . ($action=='any'?'selected':'') . ">Any</option>
<option value='unlink' " . ($action=='unlink'?'selected':'') . ">Unlink</option>
</select></td></tr>
<tr><th>Result</th><td><select name='result'>
<option value='any' " . ($result=='any'?'selected':'') . ">Any</option>
<option value='0' " . ($result=='0'?'selected':'') . ">Pending</option>
<option value='1' " . ($result=='1'?'selected':'') . ">Done</option>
<option value='2' " . ($result=='2'?'selected':'') . ">Skipped</option>
</select></td></tr>
<tr><th></th><td><input type='submit' value='Filter' class='btn btn-primary' /> <a href='?'>Reset form</a></td></tr>
</tbody>
</table>
</form></div>" ;

$mode = get_request ( 'mode' , 'latest' ) ;

$cd = new CommonsDelinquent() ;
$db = $cd->getToolDB() ;

if ( $mode == 'latest' ) {
	$num = get_request ( 'num' , 100 ) * 1 ;
	$offset = get_request ( 'offset' , 0 ) * 1 ;
	
	$where = array() ;
	if ( $image != '' ) $where[] = "file='" . $cd->getDBsafe(ucfirst(str_replace(' ','_',$image))) . "'" ;
	if ( $action != 'any' ) $where[] = "action='" . $cd->getDBsafe($action) . "'" ;
	if ( $result != 'any' ) $where[] = "done='" . $cd->getDBsafe($result*1) . "'" ;
	
	$sql = "SELECT * FROM event" ;
	if ( count($where) > 0 ) $sql .= " WHERE " . implode(" AND ",$where) ;
	$sql .= " ORDER BY timestamp DESC,log_timestamp DESC limit $num offset $offset" ;
//	print "<pre>$sql</pre>" ;
	$result = $cd->runQuery ( $db , $sql ) ;
	print "<table class='table table-condensed table-striped'>" ;
	print "<thead><th>Time</th><th>File</th><th>Page</th><th>Status</th></thead>" ;
	print "<tbody style='font-size:9pt'>" ;
	while($o = $result->fetch_object()) {
		print "<tr>" ;
		print "<td nowrap>" . substr($o->timestamp,0,4).'-'.substr($o->timestamp,4,2).'-'.substr($o->timestamp,6,2).'&nbsp;'.substr($o->timestamp,8,2).':'.substr($o->timestamp,10,2).':'.substr($o->timestamp,12,2) ;
		if ( $o->action == 'replace' ) print "<br/><i>Replacing file</i>" ;
		print "</td>" ;
		
		print "<td><a target='_blank' href='//commons.wikimedia.org/wiki/Special:Log?page=File:" . htmlspecialchars($o->file) . "'>" . str_replace('_',' ',$o->file) . "</a>" ;
		if ( $o->action == 'replace' ) print "<br/>&Rarr;<a target='_blank' href='//commons.wikimedia.org/wiki/Special:Log?page=File:" . htmlspecialchars($o->replace_with_file) . "'>" . str_replace('_',' ',$o->replace_with_file) . "</a>" ;
		print "</td>" ;

		print "<td><a target='_blank' title='Bot edits' href='//" . $cd->wiki2server($o->wiki) . "/wiki/Special:Contributions/" . urlencode($cd->config['name']) . "'>" . $o->wiki . "</a>:" ;
		print "<a target='_blank' href='//" . $cd->wiki2server($o->wiki) . "/wiki/" . htmlspecialchars($o->page) . "'>" . str_replace('_',' ',$o->page) . "</a></td>" ;

		print "<td style='width:120px'>" . $status[$o->done] ;
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