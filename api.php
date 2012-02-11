<?php
/**
 * Copyright (C) 2012 Joshua Balfour
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of
 * this software and associated documentation files (the "Software"), to deal in
 * the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
 * of the Software, and to permit persons to whom the Software is furnished to do
 	* so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

/**
 * A RESTless API for thepiratebay
 * Scrapes the search query page for the table of data, gets the data from it, then returns it in JSON form
 * Requires cURL
 * @param  string q query
 * @param  page integer page number
 * @param  orderby integer order by (seeders, leechers, upload date etc.) format dictated by thepiratebay
 * @return JSON array format:
 [ ["name","magnet link","number of comments","uploader's name","upload date","size","category","subcategory","seeders","leecher","trusted (1 or 0)"], ... ]
 */

// change this if thepiratebay ever changes their domain, also accepts IP addresses.
// no http:// or www.
// example: thepiratebay.se
$domain = "thepiratebay.se";

// classes begin ///

    /*----------------------------------------------------------------------
        Table Extractor
        ===============
        Table extractor is a php class that can extract almost any table
        from any html document/page, and then convert that html table into
        a php array.
        
        Version 1.3
        Compatibility: PHP 4.4.1 +
        Copyright Jack Sleight - www.reallyshiny.com
        This script is licensed under the Creative Commons License.
    ----------------------------------------------------------------------*/
 
    class tableExtractor {
    
        var $source            = NULL;
        var $anchor            = NULL;
        var $anchorWithin    = false;
        var $headerRow        = true;
        var $startRow        = 0;
        var $maxRows        = 0;
        var $startCol        = 0;
        var $maxCols        = 0;
        var $stripTags        = false;
        var $extraCols        = array();
        var $rowCount        = 0;
        var $dropRows        = NULL;
        
        var $cleanHTML        = NULL;
        var $rawArray        = NULL;
        var $finalArray        = NULL;
        
        function extractTable() {
        
            $this->cleanHTML();
            $this->prepareArray();
            
            return $this->createArray();
            
        }
    
 
        function cleanHTML() {
        
            // php 4 compatibility functions
            if(!function_exists('stripos')) {
                function stripos($haystack,$needle,$offset = 0) {
                   return(strpos(strtolower($haystack),strtolower($needle),$offset));
                }
            }
                        
            // find unique string that appears before the table you want to extract
            if ($this->anchorWithin) {
                /*------------------------------------------------------------
                    With thanks to Khary Sharp for suggesting and writing
                    the anchor within functionality.
                ------------------------------------------------------------*/                
                $anchorPos = stripos($this->source, $this->anchor) + strlen($this->anchor);
                $sourceSnippet = strrev(substr($this->source, 0, $anchorPos));
                $tablePos = stripos($sourceSnippet, strrev(("<table"))) + 6;
                $startSearch = strlen($sourceSnippet) - $tablePos;
            }                       
            else {
                $startSearch = stripos($this->source, $this->anchor);
            }
        
            // extract table
            $startTable = stripos($this->source, '<table', $startSearch);
            $endTable = stripos($this->source, '</table>', $startTable) + 8;
            $table = substr($this->source, $startTable, $endTable - $startTable);
        
            if(!function_exists('lcase_tags')) {
                function lcase_tags($input) {
                    return strtolower($input[0]);
                }
            }
            
            // lowercase all table related tags
            $table = preg_replace_callback('/<(\/?)(table|tr|th|td)/is', 'lcase_tags', $table);
            
            // remove all thead and tbody tags
            $table = preg_replace('/<\/?(thead|tbody).*?>/is', '', $table);
            
            // replace th tags with td tags
            $table = preg_replace('/<(\/?)th(.*?)>/is', '<$1td$2>', $table);
                                    
            // clean string
            $table = trim($table);
            $table = str_replace("\r\n", "", $table); 
                            
            $this->cleanHTML = $table;
        
        }
        
        function prepareArray() {
        
            // split table into individual elements
            $pattern = '/(<\/?(?:tr|td).*?>)/is';
            $table = preg_split($pattern, $this->cleanHTML, -1, PREG_SPLIT_DELIM_CAPTURE);    
 
            // define array for new table
            $tableCleaned = array();
            
            // define variables for looping through table
            $rowCount = 0;
            $colCount = 1;
            $trOpen = false;
            $tdOpen = false;
            
            // loop through table
            foreach($table as $item) {
            
                // trim item
                $item = str_replace(' ', '', $item);
                $item = trim($item);
                
                // save the item
                $itemUnedited = $item;
                
                // clean if tag                                    
                $item = preg_replace('/<(\/?)(table|tr|td).*?>/is', '<$1$2>', $item);
 
                // pick item type
                switch ($item) {
                    
 
                    case '<tr>':
                        // start a new row
                        $rowCount++;
                        $colCount = 1;
                        $trOpen = true;
                        break;
                        
                    case '<td>':
                        // save the td tag for later use
                        $tdTag = $itemUnedited;
                        $tdOpen = true;
                        break;
                        
                    case '</td>':
                        $tdOpen = false;
                        break;
                        
                    case '</tr>':
                        $trOpen = false;
                        break;
                        
                    default :
                    
                        // if a TD tag is open
                        if($tdOpen) {
                        
                            // check if td tag contained colspan                                            
                            if(preg_match('/<td [^>]*colspan\s*=\s*(?:\'|")?\s*([0-9]+)[^>]*>/is', $tdTag, $matches))
                                $colspan = $matches[1];
                            else
                                $colspan = 1;
                                                    
                            // check if td tag contained rowspan
                            if(preg_match('/<td [^>]*rowspan\s*=\s*(?:\'|")?\s*([0-9]+)[^>]*>/is', $tdTag, $matches))
                                $rowspan = $matches[1];
                            else
                                $rowspan = 0;
                                
                            // loop over the colspans
                            for($c = 0; $c < $colspan; $c++) {
                                                    
                                // if the item data has not already been defined by a rowspan loop, set it
                                if(!isset($tableCleaned[$rowCount][$colCount]))
                                    $tableCleaned[$rowCount][$colCount] = $item;
                                else
                                    $tableCleaned[$rowCount][$colCount + 1] = $item;
                                    
                                // create new rowCount variable for looping through rowspans
                                $futureRows = $rowCount;
                                
                                // loop through row spans
                                for($r = 1; $r < $rowspan; $r++) {
                                    $futureRows++;                                    
                                    if($colspan > 1)
                                        $tableCleaned[$futureRows][$colCount + 1] = $item;
                                    else                    
                                        $tableCleaned[$futureRows][$colCount] = $item;
                                }
    
                                // increase column count
                                $colCount++;
                            
                            }
                            
                            // sort the row array by the column keys (as inserting rowspans screws up the order)
                            ksort($tableCleaned[$rowCount]);
                        }
                        break;
                }    
            }
            // set row count
            if($this->headerRow)
                $this->rowCount    = count($tableCleaned) - 1;
            else
                $this->rowCount    = count($tableCleaned);
            
            $this->rawArray = $tableCleaned;
            
        }
        
        function createArray() {
            
            // define array to store table data
            $tableData = array();
            
            // get column headers
            if($this->headerRow) {
            
                // trim string
                $row = $this->rawArray[$this->headerRow];
                            
                // set column names array
                $columnNames = array();
                $uniqueNames = array();
                        
                // loop over column names
                $colCount = 0;
                foreach($row as $cell) {
                                
                    $colCount++;
                    
                    $cell = strip_tags($cell);
                    $cell = trim($cell);
                    
                    // save name if there is one, otherwise save index
                    if($cell) {
                    
                        if(isset($uniqueNames[$cell])) {
                            $uniqueNames[$cell]++;
                            $cell .= ' ('.($uniqueNames[$cell] + 1).')';    
                        }            
                        else {
                            $uniqueNames[$cell] = 0;
                        }
 
                        $columnNames[$colCount] = $cell;
                        
                    }                        
                    else
                        $columnNames[$colCount] = $colCount;
                    
                }
                
                // remove the headers row from the table
           //  unset($this->rawArray[$this->headerRow]);
             
            }
            
            // remove rows to drop
            foreach(explode(',', $this->dropRows) as $key => $value) {
                unset($this->rawArray[$value]);
            }
                                
            // set the end row
            if($this->maxRows)
                $endRow = $this->startRow + $this->maxRows - 1;
            else
                $endRow = count($this->rawArray);
                
            // loop over row array
            $rowCount = 0;
            $newRowCount = 0;                            
            foreach($this->rawArray as $row) {
            
                $rowCount++;
                
                // if the row was requested then add it
                if($rowCount >= $this->startRow && $rowCount <= $endRow) {
                
                    $newRowCount++;
                                    
                    // create new array to store data
                    $tableData[$newRowCount] = array();
                    
                    //$tableData[$newRowCount]['origRow'] = $rowCount;
                    //$tableData[$newRowCount]['data'] = array();
                    $tableData[$newRowCount] = array();
                    
                    // set the end column
                    if($this->maxCols)
                        $endCol = $this->startCol + $this->maxCols - 1;
                    else
                        $endCol = count($row);
                    
                    // loop over cell array
                    $colCount = 0;
                    $newColCount = 0;                                
                    foreach($row as $cell) {
                    
                        $colCount++;
                        
                        // if the column was requested then add it
                        if($colCount >= $this->startCol && $colCount <= $endCol) {
                    
                            $newColCount++;
                            
                            if($this->extraCols) {
                                foreach($this->extraCols as $extraColumn) {
                                    if($extraColumn['column'] == $colCount) {
                                        if(preg_match($extraColumn['regex'], $cell, $matches)) {
                                            if(is_array($extraColumn['names'])) {
                                                $this->extraColsCount = 0;
                                                foreach($extraColumn['names'] as $extraColumnSub) {
                                                    $this->extraColsCount++;
                                                    $tableData[$newRowCount][$extraColumnSub] = $matches[$this->extraColsCount];
                                                }                                        
                                            } else {
                                                $tableData[$newRowCount][$extraColumn['names']] = $matches[1];
                                            }
                                        } else {
                                            $this->extraColsCount = 0;
                                            if(is_array($extraColumn['names'])) {
                                                $this->extraColsCount = 0;
                                                foreach($extraColumn['names'] as $extraColumnSub) {
                                                    $this->extraColsCount++;
                                                    $tableData[$newRowCount][$extraColumnSub] = '';
                                                }                                        
                                            } else {
                                                $tableData[$newRowCount][$extraColumn['names']] = '';
                                            }
                                        }
                                    }
                                }
                            }
                            
                         //   if($this->stripTags)        
                              //  $cell = strip_tags($cell);
                            
                            // set the column key as the column number
                            $colKey = $newColCount;
                            
                            // if there is a table header, use the column name as the key
                            if($this->headerRow)
                                if(isset($columnNames[$colCount]))
                                    $colKey = $columnNames[$colCount];
                            
                            // add the data to the array
                            //$tableData[$newRowCount]['data'][$colKey] = $cell;
                            $tableData[$newRowCount][$colKey] = $cell;
                        }
                    }
                }
            }
            $this->finalArray = $tableData;
            return $tableData;
        }    
    }

/**
 * The Pirate Bay Proxy class
 * Allows the user to easily create a proxy to The Pirate Bay.
 * Simple copy both the .htaccess and this file to any map in the webserver
 * and that's it.
 * 
 * Afterwards, please add your proxy to IKWILTHEPIRATEBAY.NL so that
 * people can find it.
 */
class Proxy
{
    /**
     *
     * @var curl_handle
     */
    protected $ch;
    
    public function __construct()
    {
        $this->ch = curl_init();
        curl_setopt($this->ch, CURLOPT_HEADER, true);
        curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
        @curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($this->ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($this->ch, CURLOPT_USERAGENT, 'Opera/9.23 (Windows NT 5.1; U; en)');
    }
    
    /**
     * Run
     * @param string $url
     * @param array $get $_GET global var
     * @param array $post $_POST global var
     * @return string Response 
     */
    public function run($url, $get, $post)
    {
        
        // Apppend get params to request
        if($get) {
            $url .= '?'.http_build_query($get);
        }
        
        curl_setopt($this->ch, CURLOPT_URL, $url);
        
        // set optional post params
        if($post) {
            curl_setopt($this->ch, CURLOPT_POSTFIELDS, $post);
            curl_setopt($this->ch, CURLOPT_POST, true);
        }
        
        // See below
        $return = $this->curlExecFollow($this->ch);
        
        // Throw exception on error
        if($return === false)
            throw new Exception($this->error());
        
        return $return;
    }
    
    
    /**
     * Get error message
     * @return string 
     */
    protected function error()
    {
        return curl_error($this->ch);
    }
    
    /**
     * Allow redirects under safe mode
     * @param curl_handle $ch
     * @return string 
     */
    protected function curlExecFollow($ch)
    {
        $mr = 5; 
        if (ini_get('open_basedir') == '' && (ini_get('safe_mode') == 'Off' || ini_get('safe_mode') == '')) { 
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $mr > 0); 
            curl_setopt($ch, CURLOPT_MAXREDIRS, $mr); 
        } else { 
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); 
            if ($mr > 0) { 
                $newurl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL); 

                $rch = curl_copy_handle($ch); 
                curl_setopt($rch, CURLOPT_HEADER, true); 
                curl_setopt($rch, CURLOPT_NOBODY, true); 
                curl_setopt($rch, CURLOPT_FORBID_REUSE, false); 
                curl_setopt($rch, CURLOPT_RETURNTRANSFER, true); 
                do { 
                    if(strpos($newurl, '/') === 0)
                            $newurl = $this->prefix.$newurl;
                    
                    curl_setopt($rch, CURLOPT_URL, $newurl); 
                    $header = curl_exec($rch); 
                    if (curl_errno($rch)) { 
                        $code = 0; 
                    } else { 
                        $code = curl_getinfo($rch, CURLINFO_HTTP_CODE); 
                        if ($code == 301 || $code == 302) { 
                            preg_match('/Location:(.*?)\n/', $header, $matches); 
                            $newurl = str_replace(' ', '%20', trim(array_pop($matches)));
                        } else { 
                            $code = 0; 
                        } 
                    } 
                } while ($code && --$mr); 
                curl_close($rch); 
                if (!$mr) { 
                    if ($maxredirect === null) { 
                        trigger_error('Too many redirects. When following redirects, libcurl hit the maximum amount.', E_USER_WARNING); 
                    } else { 
                        $maxredirect = 0; 
                    } 
                    return false; 
                } 
                curl_setopt($ch, CURLOPT_URL, $newurl); 
            } 
        } 
        return curl_exec($ch); 
    }
} 
///classes end////

///functions begin//

function get_array_from_table ($data)
{
	$tbl = new tableExtractor;
	$tbl->source = $data; // Set the HTML Document
	$tbl->anchor = ''; // Set an anchor that is unique and occurs before the Table
	$tpl->anchorWithin = true; // To use a unique anchor within the table to be retrieved
	$d = $tbl->extractTable(); // The array
	return $d;
	unset($tbl);
	unset($tpl);
	unset($d);
}


function GetBetween($content,$start,$end){
	$r = explode($start, $content);
	if (isset($r[1])){
		$r = explode($end, $r[1]);
		return $r[0];
	}
	return '';
}

function strip_tags_array($data, $tags = null)
{
	$stripped_data = array();
	foreach ($data as $value)
	{
		if (is_array($value))
		{
			$stripped_data[] = strip_tags_array($value, $tags);
		}
		else
		{
			$stripped_data[] = strip_tags($value, $tags);
		}
	}
	return $stripped_data;
}
///functions end//

///////////main code start//////////////////

//try the proxy, if it fails spit out an error message and send the error 500 code
try {
	$url="http://$domain/search/";
    $proxy = new Proxy();
    $return= $proxy->run($url, $_GET, $_POST);
} catch(Exception $e) {
	header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(array('Error',$e->getMessage()));
}

//get the array from the table we got
$data = get_array_from_table($return);

//first row is going to be the heading, don't want that.
array_shift($data);

//declare the new array we're going to shift formatted data into
$newData=array();

foreach($data as $item)
{
	
	$name=GetBetween($item["Name(Orderby:Uploaded,Size,ULedby,SE,LE)View:Single/Double&nbsp;"], '<ahref="', '"');
	$name=GetBetween($name,'/torrent/','class');
	$name=GetBetween($name,"/",'"');
	$name=str_replace("_"," ",$name);
	
	$magnet="magnet:?".GetBetween($item["Name(Orderby:Uploaded,Size,ULedby,SE,LE)View:Single/Double&nbsp;"], '<ahref="magnet:?', '"');
	
	$comments=GetBetween($item["Name(Orderby:Uploaded,Size,ULedby,SE,LE)View:Single/Double&nbsp;"],'title="Thistorrenthas','comments.');
	if ($comments==""){$comments =0;}else {$comments=intval($comments);};
	
	if (substr_count($item["Name(Orderby:Uploaded,Size,ULedby,SE,LE)View:Single/Double&nbsp;"], '<imgsrc="http://static.thepiratebay.se/img/trusted.png"alt="Trusted"title="Trusted"style="width:11px;"border=')>0){
		$trusted=1;
	} else {$trusted=0;};
	
	$uploader=GetBetween($item["Name(Orderby:Uploaded,Size,ULedby,SE,LE)View:Single/Double&nbsp;"],'href="/user/','"');
	$uploader=str_replace("&nbsp;"," ",$uploader);
	$uploader=str_replace("/","",$uploader);
	
	$uploaded=GetBetween($item["Name(Orderby:Uploaded,Size,ULedby,SE,LE)View:Single/Double&nbsp;"],'<fontclass="detDesc">',',');
	$uploaded=str_replace("&nbsp;","-",$uploaded);
	$uploaded=str_replace("Uploaded","",$uploaded);
	
	$size=GetBetween($item["Name(Orderby:Uploaded,Size,ULedby,SE,LE)View:Single/Double&nbsp;"],'Size',',ULedby');
	$size=str_replace("&nbsp;"," ",$size);
	
	$category=GetBetween($item["Type"], '"Morefromthiscategory">', '</a>');
	
	$subcategory=GetBetween($item["Type"],'<br/>','</center>');
	$subcategory=GetBetween($subcategory,'"Morefromthiscategory">','</a>');
	
	$seeders=$item["SE"];
	
	$leechers=$item["LE"];
	
	//create an array with the formatted data in
	$newItem=array($name,$magnet,$comments,$uploader,$uploaded,$size,$category,$subcategory,$seeders,$leechers,$trusted);
	
	//push it onto the $newData array
	array_push($newData, $newItem);
}

echo json_encode($newData);
 ?>