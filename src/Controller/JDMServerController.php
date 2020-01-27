<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\File\File;
use Knp\Component\Pager\PaginatorInterface;


const PATH_CACHE = 'cache/terms/';
header("Content-Type: text/html;charset=ISO-8859-1");
ini_set("pcre.backtrack_limit", "50000000");

Global $obj;
$obj = new \stdClass();
Global $term;
$term = array();
Global $rels_out;
$rels_out = array();
Global $rels_in;
$rels_in = array();
Global $rts;
$rts = array();
Global $ent;
$entries = array();
Global $defs;
$defs = array();
Global $sort;
$sort = "weight";
Global $useless_rt;
$useless_rt = [12,18,19,29,33,36,45,46,47,48,66,118,128,200,444,555,1000,1001,1002,2001];


class JDMServerController extends Controller
{
    /**
     * @Route("/", name="jdm_server")
     */
    public function index()
    {

    	$term =" ";
    	$content =" ";

    	return $this->render('jdm_server/index.html.twig', [
    		'term' => $term,
    		'content' => $content
    	]);
    }

	/**
     * @Route("/search-term", name="search-term")
     */
	public function searchTerm(Request $request)
	{
		$term = $request->get('term');

		$filesystem = new Filesystem();

		$unsuportChars = array("*", ".", "\"", "/", "\\", "[", "]", ":", ";", "|", ",");
		$term = str_replace(" ","+",$term);
		$term = str_replace($unsuportChars ,"", $term);

		$path = PATH_CACHE.$term.'.json';

		if($filesystem->exists($path) && $this->recentFile($filesystem, $path)){

			$file = new File($path);
		}
		else{//Download and transform File
			$file = $this->downloadFile($term, $path);
		}
		
		$contentToArray = $this->twig_json_decode(file_get_contents($path));

		$pagination = $this->get('knp_paginator')->paginate(
			$contentToArray,
			$request->query->get('page',1),
			2
		);


		return $this->render('jdm_server/index.html.twig', [
			'term' => $term,
			'content' => $contentToArray       
		]);
	}
	
	public function recentFile($filesystem, $path)
	{
		$file_timestamp = filemtime($path);
		$file_date = date("F d Y H:i:s.", $file_timestamp);

		if($file_timestamp < strtotime('- 30 days')){
			$filesystem->remove($path);
			return False;
		}
		return True;

	}


	public function downloadFile($term, $path): File
	{	
		global $obj;
		global $entries;
		global $rels_in;
		global $rels_out;


		$filesystem = new Filesystem();
		$client = HttpClient::create();

		$response = $client->request('GET', 'http://www.jeuxdemots.org/rezo-dump.php?gotermsubmit=Chercher&gotermrel='.urlencode(iconv("UTF-8","ISO-8859-1", $term)).'&rel=');

		$contentType = $response->getHeaders();

		$content = $response->getContent();

		$this->extractData($content);


		try {
			    $filesystem->touch($path);
			    $filesystem->appendToFile($path, json_encode($obj));
			} 
		catch (IOExceptionInterface $exception) {
			    echo "An error occurred while creating the file at ".$exception->getPath();
		}

		return new File($path);
	}

	public function extractData($content)
	{
		global $obj;
		global $term;
		global $defs;
		global $entries;
		global $rts;
		global $rels_in;
		global $rels_out;
		global $useless_rt;

		$codeTag = $this->getCodeTag($content);

		if($codeTag !=''){
			$separator = "\r\n";
			$line = strtok($codeTag, $separator);
			$exploded = array();
			$isFirst = True;

			while ($line !== false) {
				//Match with definitions which starts with number
				if(preg_match("/^\d\..*/",$line)){
					array_push($defs, $line);
				}
				//Matchs with Entries
				elseif($this->startsWith($line, 'e;')){
					$exploded = explode(';',$line);

					if(count($exploded)==5){
						$arr_e = array('lt' => $exploded[0], 'eid' => $exploded[1], 'name' => trim($exploded[2], "'"), 'type' => $exploded[3], 'w' => $exploded[4]);

						if($isFirst){
							$isFirst = False;
							$term = $arr_e;
							$obj->term = $term;
						}
						$entries[$exploded[1]] = $arr_e;
					}
					elseif(count($exploded)==6){
						$arr_e = array('lt' => $exploded[0], 'eid' => $exploded[1], 'name' => trim($exploded[5], "'"), 'type' => $exploded[3], 'w' => $exploded[4]);

						if($isFirst){
							$isFirst = False;
							$term = $arr_e;
							$obj->term = $term;
						}
						$entries[$exploded[1]] = $arr_e;
					}
				}
				//Match with Relation Types
				elseif($this->startsWith($line, 'rt;')){
					$exploded = explode(';', $line);

					if(!in_array($exploded[1], $useless_rt)){
						$arr_rt = array('lt' => $exploded[0], 'rtid' => $exploded[1], 'trname' => $exploded[2], 'trgpname' => $exploded[3], 'rthelp' => $exploded[4]);
						array_push($rts, $arr_rt);
						//$rts[$exploded[1]] = $arr_rt;
					}
				}
				//Match with Relations
				elseif($this->startsWith($line, "r;")){
					$exploded = explode(';', $line);
					$w = $exploded[5];
					settype($w, "integer");

					if(!in_array($exploded[4], $useless_rt)){
						if(array_key_exists($exploded[2], $entries)){
							$node1 = $entries[$exploded[2]]['name'];
						}
						else{
							$node1 = $exploded[2];
						}
						if(array_key_exists($exploded[3], $entries)){
							$node2 = $entries[$exploded[3]]['name'];
						}
						else{
							$node2 = $exploded[3];
						}

						$arr_r = array('lt' => $exploded[0], 'rid' => $exploded[1], 'node1' => $node1, 'node2' => $node2, 'type' => $exploded[4], 'w' => $w);

						if($exploded[2] == $term['eid']){
							array_push($rels_out, $arr_r);
						}
						else{
							array_push($rels_in, $arr_r);
						}
					}
				}
				//Match with definition which doesn't start with number
				elseif(!preg_match("/\/\/.*/",$line) && !$this->startsWith($line,'nt;') && !$this->startsWith($line, 'e;') && !$this->startsWith($line, 'rt;') && !$this->startsWith($line, 'r;')){
						array_push($defs, $line);
				}
				

				$line = strtok($separator);
			}
		}

		usort($rels_out,function ($rel1, $rel2) {
			if ($rel1['w'] == $rel2['w']) {
			    return 0;
			}
				return ($rel1['w'] > $rel2['w']) ? -1 : 1;
			});
		usort($rels_in,function ($rel1, $rel2) {
			if ($rel1['w'] == $rel2['w']) {
			    return 0;
			}
				return ($rel1['w'] > $rel2['w']) ? -1 : 1;
			});

		$obj->defs = $defs;
		$obj->term = $term;
		$obj->rts = $rts;
		$obj->rels_out = $rels_out;
		$obj->rels_in = $rels_in;
		
	}

	public function getCodeTag($content)
	{
		$crawler = new Crawler($content);
		$code = $crawler->filter('CODE')->text('Pas de résultat pour ce terme!');

		return $code;
	}

	public function getJsonDefs($defintions) 
	{ 	
		$defs = utf8_decode ($defintions);
		$defs = utf8_encode($defintions);
		$arr_defs = preg_split("/\d./", $defintions);

		if(sizeof($arr_defs)>1){
			$output = array_slice($arr_defs, 1);
		}
		else{
			$output = $defintions;
		}

		$json = json_encode($output, JSON_UNESCAPED_UNICODE |JSON_FORCE_OBJECT |JSON_UNESCAPED_LINE_TERMINATORS|JSON_PRETTY_PRINT);
		
		return $json;
	}

	public function startsWith($haystack, $needle)
	{

    	$length = strlen($needle);
    	$sub = substr($haystack, 0, $length);

    	return ($sub === $needle);
	}

	public function twig_json_decode($json)
	{
	    return json_decode($json, true, JSON_UNESCAPED_UNICODE);
	}


}
