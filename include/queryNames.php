<?php

require_once "./include/treat_word.inc";
require_once "./include/cleanname.inc";

require_once "./include/treat_word_c.inc";

/* normalize input data
if (empty($name)) {
	return;
}

$name = str_replace("　", " ", trim($name, " \t"));
while (preg_match('/[\s]{2,}/', $name)) {
	$name = preg_replace('/\s\s/', " ", $name);
}

if (!empty($name)) {
	$name = canonical_form($name);
	queryNames($name);
}
//*/

// echo treat_word_c('台灣');

function sortByKeyOrder(array $sortOrderKeys, array $arrayToSort){
	$output = [];
	$new_index = 0;
	foreach($sortOrderKeys as $index){
	  if(isset($arrayToSort[$index])){
		  $output[$new_index] = $arrayToSort[$index];
		  $new_index += 1;
	  }
	}
 
   return $output;
 }

// kim: 2022-04新增，比對屬以上階層，一次比對一個name
// *** rank要限制在種以上嗎?
// $ep = 'http://solr:8983/solr/taxa';
function queryNameSingle($name, $against, $best, $ep){
	// kim: 搜尋 canonical_name or common_name_c
	$name_cleaned = canonical_form($name, true);
	$columns = array(
		'matched',
		'common_name',
		'accepted_namecode',
		'namecode',
		'source',
		'url_id',
		'a_url_id',
		'kingdom',
		'phylum',
		'class',
		'order',
		'family',
		'genus',
		'taxon_rank');

	/**
	 * @todo 中文的話best比對方式要修改 
	 * @todo 中文異體字
	 */
	if ($best=='yes'&&!(preg_match("/\p{Han}+/u", $name))) {
		$ep .= '/select?wt=json&fq=is_single_word%3Atrue&rows=0&q=' . rawurlencode($name_cleaned) .'~1';
	} 
	elseif (preg_match("/\p{Han}+/u", $name)) { // 是中文
		$name_cleaned = $name;
		$ep .= '/select?wt=json&rows=0&q=common_name_c:/.*' . rawurlencode(treat_word_c($name)) . '.*/';
	}
	elseif (!preg_match("/\p{Han}+/u", $name)) { // 不是best, 不是中文
		$ep .= '/select?wt=json&fq=is_single_word%3Atrue&rows=0&q=' . rawurlencode($name_cleaned) .'~';
	} 

	/*
	elseif (preg_match("/\p{Han}+/u", $name) { // 不是best, 是中文
http://solr:8983/solr/taxa/select?wt=json&rows=0&q=%E9%90%B5%E6%9D%89
http://solr:8983/solr/taxa/select?wt=json&rows=0&q=Taiwania
	}*/
	// extract_results("", "", $reset=true);
	extract_results($ep, '', $reset=false, $against=$against);
	$all_matched_tmp = extract_results();
	
	if (!$all_matched_tmp['']['type']=='No match'){
		foreach ($all_matched_tmp as $m) {
			// 根據source排序但先保留原始index
			// 其他欄位也用source排序
			arsort($m['source']);
			// print_r($m['source']);
			$reorder = array_keys($m['source']);
			foreach ($columns as $c) {
				$m[$c] = sortByKeyOrder($reorder, $m[$c]);
				$m[$c] = array_values($m[$c]);
			}		
			$all_matched[$m['matched_clean']] = array_merge(array('name' => $name, 'name_cleaned' => $name_cleaned), $m);
		}
	} else {
		foreach ($all_matched_tmp as $m) {
			$all_matched[$m['matched_clean']] = array_merge(array('name' => $name, 'name_cleaned' => $name_cleaned), $m);
		}
	}
	return $all_matched;
}

// kim: 以下為原始演算法，如果超過單字，優先以種&種下的方式比對 
function queryNames ($name, $against, $best, $ep) {

	$columns = array(
		'matched',
		'common_name',
		'accepted_namecode',
		'namecode',
		'source',
		'url_id',
		'a_url_id',
		'kingdom',
		'phylum',
		'class',
		'order',
		'family',
		'genus',
		'taxon_rank');


	if (empty($ep)) return false;

	$ep .= '/select?wt=json&q=*:*&sort=source%20asc'; 
	// $ep = 'http://localhost:8983/solr/taxa/select?wt=json&q=*:*';
	// $ep = 'http://solr:8983/solr/taxa/select?wt=json&q=*:*';
	// $ep = 'http://140.109.28.72/solr4/taxa/select?wt=json&q=*:*';

	extract_results("", "", $reset=true);
	// mix2; work with latin part b2, c2, and suggestions of latin part b2, c2
	$mix2 = array();
	$sound_mix2 = array();
	$matched = array();
	$info = array();
	$suggestions = array();
	$long_suggestions = array();

	$name_cleaned = canonical_form($name, true);

	$parts = explode(" ", $name_cleaned);

	$lpa2 = $parts[0];
	$lpb2 = @$parts[1];
	$lpc2 = @$parts[2];

	$spa2 = treat_word($lpa2);
	$spb2 = treat_word($lpb2);
	$spc2 = treat_word($lpc2);

	if (!empty($parts[1])) {
		$mix2[] = $parts[1];
	}
	else {
//		return null;
		return	array('N/A' => array(
					'name' => $name,
					'name_cleaned' => $name_cleaned,
					'matched' => 'N/A',
					'matched_clean' => 'N/A',
					'common_name' => 'N/A',
					'accepted_namecode' => array(),
					'namecode' => array(),
					'source' => array(),
					'url_id' => array(),
					'a_url_id' => array(),
					'kingdom' => array(),
					'phylum' => array(),
					'class' => array(),
					'order' => array(),
					'family' => array(),
					'genus' => array(),
					'type' => 'N/A',
					'taxon_rank' => array(),
				)
			);

	}
	if (!empty($parts[2])) {
		$mix2[] = $parts[2];
	}

	if (!empty($spb2)) {
		$sound_mix2[] = $spb2;
	}
	if (!empty($spc2)) {
		$sound_mix2[] = $spc2;
	}


	// Type 1
	$query_url_1 = $ep . '&fq=canonical_name:"' . urlencode($name_cleaned) . '"';
        //echo $query_url_1; echo "------";
	extract_results($query_url_1, TYPE_1, $reset=false, $against);

	// with minor spell error
	$query_url_1_err_suggestion = $ep . "&rows=0&spellcheck.q=" . urlencode(implode(" ", $mix2)) ;
	$suggestion = extract_suggestion ($query_url_1_err_suggestion, TYPE_1_E);
	if (!empty($suggestion)) {
		$query_url_1_err = $ep . '&fq=canonical_name:"' . urlencode("$lpa2 $suggestion") . '"';
		extract_results($query_url_1_err, TYPE_1_E, $reset=false, $against);
	}

	//*
	$query_url_1_err_long_suggestion = $ep . "&rows=0&spellcheck.q=" . urlencode($name_cleaned) ;
	$long_suggestion = extract_suggestion ($query_url_1_err_long_suggestion, TYPE_1_E);
	if (!empty($long_suggestion)) {
		$query_url_1_err = $ep . '&fq=latin_part_a:' . $lpa2 . '&fq=canonical_name:"' . urlencode("$long_suggestion") . '"';
		extract_results($query_url_1_err, TYPE_1_E, $reset=false, $against);
	}
	//*/
	$all_matched_tmp = extract_results();

	if (!empty($all_matched_tmp['']) || $best == 'no') {

		// Type 2
		$query_url_2 = $ep . '&fq=latin_part_a:' . urlencode($lpa2) . '&fq=latin_part_bc:(' . urlencode(implode(' OR ', $mix2)) . ")";
		extract_results($query_url_2, TYPE_2, $reset=false, $against);

		// with minor spell error
		foreach (array_unique($mix2) as $p) {
			$query_url_2_err_suggestion = $ep . "&rows=0&spellcheck.q=" . urlencode($p) ;
			$suggestion = extract_suggestion ($query_url_2_err_suggestion, TYPE_2_E);
			if (!empty($suggestion)) {
				$suggestions[] = $suggestion;
			}
			$query_url_2_err_long_suggestion = $ep . "&rows=0&spellcheck.q=" . urlencode("$lpa2 $p") ;
			$long_suggestion = extract_suggestion ($query_url_2_err_long_suggestion, TYPE_2_E);
			if (!empty($long_suggestion)) {
				$long_suggestions[] = $long_suggestion;
			}
		}
		if (!empty($suggestions)) {
			$suggestions = array_unique(array_merge($suggestions, $mix2));
			$query_url_2_err = $ep . '&fq=latin_part_a:' . urlencode($lpa2) . '&fq=latin_part_bc:(' . urlencode(implode(' OR ', $suggestions)) . ")";
			extract_results($query_url_2_err, TYPE_2_E, $reset=false, $against);
		}
		if (!empty($long_suggestions)&&(count($mix2)>1)) {
			foreach ($long_suggestions as $long_suggestion) {
                $query_url_2_err = $ep . '&fq=canonical_name:"' . urlencode($long_suggestion) . '"';
				extract_results($query_url_2_err, TYPE_2_E, $reset=false, $against);
			}
		}

		// Genus spell error???
		$query_url_2_genus_err_suggestion = $ep . "&rows=0&spellcheck.q=" . urlencode($lpa2) ;
		$suggestion = extract_suggestion ($query_url_2_genus_err_suggestion, TYPE_2_GE);

        if (is_null($suggestion)) {
			$query_url_2_genus_err_suggestion = $ep . "&rows=0&spellcheck.q=" . urlencode($name_cleaned);
			$suggestion = array_shift(explode(" ", extract_suggestion ($query_url_2_genus_err_suggestion, TYPE_2_GE)));

			if (is_null($suggestion)) {
				foreach ($mix2 as $mp) {
					$query_url_2_genus_err_suggestion = $ep . "&rows=0&spellcheck.q=" . urlencode($lpa2 + ' ' + $mp);
					$suggestion = array_shift(explode(" ", extract_suggestion ($query_url_2_genus_err_suggestion, TYPE_2_GE)));
					if (!is_null($suggestion)) {
						break;
					}
				}
			}
		}


		if (treat_word($lpa2, true) == treat_word($suggestion, true)) {
			$query_url_2_genus_err = $ep . '&fq=latin_part_a:' . urlencode($suggestion) . '&fq=latin_part_bc:(' . urlencode(implode(' OR ', $mix2)) . ")";
			extract_results($query_url_2_genus_err, TYPE_2_GS, $reset=false, $against);
		}
		elseif ((levenshtein($lpa2, $suggestion) == 1)&&(strlen($lpa2)==strlen($suggestion))) {
			$len = strlen($lpa2);
			for ($i=0; $i<$len; $i++) {
				if ($lpa2[$i] != $suggestion[$i]) {
					if (similar_char($lpa2[$i], $suggestion[$i], @$lpa2[$i+1], @$suggestion[$i+1])) {
						$query_url_2_genus_err = $ep . '&fq=latin_part_a:' . urlencode($suggestion) . '&fq=latin_part_bc:(' . urlencode(implode(' OR ', $mix2)) . ")";
						extract_results($query_url_2_genus_err, TYPE_2_GL, $reset=false, $against);
					}
				}
			}
		}
		elseif (levenshtein($lpa2, $suggestion) == 1) {
			$query_url_2_genus_err = $ep . '&fq=latin_part_a:' . urlencode($suggestion) . '&fq=latin_part_bc:(' . urlencode(implode(' OR ', $mix2)) . ")";
			extract_results($query_url_2_genus_err, TYPE_2_GL2, $reset=false, $against);
		}
		$all_matched_tmp = extract_results();
	}

	if (!empty($all_matched_tmp['']) || $best == 'no') {
		// Type 3
		$sound = treat_word($name_cleaned);
		$query_url_3 = $ep . '&fq=sound_name:"' . urlencode($sound) . '"';
		extract_results($query_url_3, TYPE_3_S, $reset=false, $against);

		// Type 3 mix
		$query_url_3 = $ep . '&fq=sound_part_a:' . urlencode($spa2) . '&fq=sound_part_bc:(' . urlencode(implode(' OR ', $sound_mix2)) . ")";
		extract_results($query_url_3, TYPE_3_S2, $reset=false, $against);

		$sound_mix2_strip_ending = array_map("treat_word", $mix2, array_fill(0, count($mix2), true));
		$query_url_3_strip_bc_ending = $ep . '&fq=sound_part_a:' . urlencode($spa2) . '&fq=sound_part_bc_strip_ending:(' . urlencode(implode(' OR ', $sound_mix2_strip_ending)) . ")";
		extract_results($query_url_3_strip_bc_ending, TYPE_3_S3, $reset=false, $against);

		$query_url_3_strip_all_ending = $ep . '&fq=sound_part_a_strip_ending:' . urlencode(treat_word($spa2, true)) . '&fq=sound_part_bc_strip_ending:(' . urlencode(implode(' OR ', $sound_mix2_strip_ending)) . ")";
		extract_results($query_url_3_strip_all_ending, TYPE_3_GUESS, $reset=false, $against);

		$all_matched_tmp = extract_results();
	}

	if (!$all_matched_tmp['']['type']=='No match'){
		foreach ($all_matched_tmp as $m) {
			// 根據source排序但先保留原始index
			// 其他欄位也用source排序
			arsort($m['source']);
			// print_r($m['source']);
			$reorder = array_keys($m['source']);
			foreach ($columns as $c) {
				$m[$c] = sortByKeyOrder($reorder, $m[$c]);
				$m[$c] = array_values($m[$c]);
			}		
			$all_matched[$m['matched_clean']] = array_merge(array('name' => $name, 'name_cleaned' => $name_cleaned), $m);
		}
	} else {
		foreach ($all_matched_tmp as $m) {
			$all_matched[$m['matched_clean']] = array_merge(array('name' => $name, 'name_cleaned' => $name_cleaned), $m);
		}
	}


/*
echo "<xmp>";
var_dump($all_matched);
echo "</xmp>";
//*/
	//var_dump($all_matched);
	return $all_matched;

}


// Functions
function extract_suggestion ($query_url="", $msg="") {
//	echo $msg . "\n";
//	echo "extract suggestion, " . $query_url . "\n";
	$jo = @json_decode(@file_get_contents($query_url));
	// moogoo: solr 8 changed the suggestions result?
	/*if (!empty($jo->spellcheck->suggestions)) {
		$vals = array_values($jo->spellcheck->suggestions);
		//echo "<xmp>";
		//var_dump($vals);
		//echo "</xmp>";
		$idx = array_search("collation", $vals);
		return trim($vals[0][$idx+1], "()");
	}*/
	if (!empty($jo->spellcheck->collations)) {
		return $jo->spellcheck->collations[1];
	}
}



function extract_results ($query_url="", $msg="", $reset=false, $against="", $is_common_name=false) {

	static $all_matched = array();
	static $query_urls = array();
	if ($reset) {
		$all_matched = array();
		$query_urls = array();
	}
	if (empty($query_url)&&!$reset) {
		return $all_matched;
	}
	if (!empty($query_url)) {

		if (!empty($against)) {
			$query_url .= "&fq=source:$against";
		}

		if (@$query_urls[$query_url]) {
			return;
		}
		$query_urls[$query_url] = true;

		$first_query = @json_decode(@file_get_contents($query_url));
		$numFound = $first_query->response->numFound;
		if ($numFound > 0){
			$docs = array();
			$rows = 100; // 每次回傳 100 rows
			$offset = 0;
			$current_url_root = str_replace('rows=0', 'rows=100' ,$query_url);
			for($i=0 ;  $offset < $numFound ; $i++){
				$offset = $rows*$i;
				$current_url = $current_url_root . '&start=' . $offset;
				$jo = @json_decode(@file_get_contents($current_url));
				$current_doc = $jo->response->docs;
				$docs = array_merge($docs, $current_doc);
			}
		}
	}
	if (!empty($docs) ) {
		foreach ($docs as $doc) {
			$doc->is_accepted = ($doc->namecode === $doc->accepted_namecode)?1:0;
			$matched[] = $doc;
			if (empty($all_matched[$doc->canonical_name])) {
				unset($all_matched['']);
				$all_matched[$doc->canonical_name] = array(
					'matched_clean' => $doc->canonical_name,
					'matched' => array(@$doc->original_name),
					'common_name' => array(@$doc->common_name_c),
					'accepted_namecode' => array(@$doc->accepted_namecode),
					'namecode' => array(@$doc->namecode),
					'source' => array(array_shift(explode("-", $doc->id))),
					'url_id' => array(@$doc->url_id),
					'a_url_id' => array(@$doc->a_url_id),
					'kingdom' => array(@$doc->kingdom),
					'phylum' => array(@$doc->phylum),
					'class' => array(@$doc->class),
					'order' => array(@$doc->order),
					'family' => array(@$doc->family),
					'genus' => array(@$doc->genus),
					'taxon_rank' => array(strtolower(@$doc->taxon_rank)),
					'type' => $msg,
				);
			}
			else {
				if (!in_array(@$doc->namecode, $all_matched[$doc->canonical_name]['namecode'])) {
					$all_matched[$doc->canonical_name]['namecode'][] = @$doc->namecode;
					$all_matched[$doc->canonical_name]['matched'][] = @$doc->original_name;
					$all_matched[$doc->canonical_name]['common_name'][] = @$doc->common_name_c;
					$all_matched[$doc->canonical_name]['source'][] = array_shift(explode("-", $doc->id));
					$all_matched[$doc->canonical_name]['accepted_namecode'][] = @$doc->accepted_namecode;
					$all_matched[$doc->canonical_name]['url_id'][] = @$doc->url_id;
					$all_matched[$doc->canonical_name]['a_url_id'][] = @$doc->a_url_id;
					$all_matched[$doc->canonical_name]['kingdom'][] = @$doc->kingdom;
					$all_matched[$doc->canonical_name]['phylum'][] = @$doc->phylum;
					$all_matched[$doc->canonical_name]['class'][] = @$doc->class;
					$all_matched[$doc->canonical_name]['order'][] = @$doc->order;
					$all_matched[$doc->canonical_name]['family'][] = @$doc->family;
					$all_matched[$doc->canonical_name]['genus'][] = @$doc->genus;
					$all_matched[$doc->canonical_name]['taxon_rank'][] = strtolower(@$doc->taxon_rank);
				}

			}
		}
//		echo "<xmp>";
//		var_dump($matched);
//		echo "</xmp>";
	}
	elseif (empty($all_matched)) {
		$all_matched[''] = array(
			'matched' => array(),
			'matched_clean' => '',
			'common_name' => '',
			'accepted_namecode' => array(),
			'namecode' => array(),
			'source' => array(),
			'url_id' => array(),
			'a_url_id' => array(),
			'kingdom' => array(),
			'phylum' => array(),
			'class' => array(),
			'order' => array(),
			'family' => array(),
			'genus' => array(),
			'taxon_rank' => array(),
			'type' => 'No match'
		);
	}
}


// confusing OCR results or hand writings
function similar_char($a, $b, $aplus1='', $bplus1='') {

	if (empty($aplus1)) $aplus1 = '';
	if (empty($bplus1)) $bplus1 = '';

	$similar_sets = array(
		array('r','m', 'n'),
		array('a', 'd'),
		array('a', 'u'),
		array('c', 'o'),
		array('c', 'r'),
		array('c', 'e'),
		array('e', 'o'),
                array('t', 'r'),
                array('A', 'E'),
	);
	$similar_2chrs = array(
		array('in' ,'m'),
		array('ni' ,'m'),
		array('rn' ,'m'),
		array('ri' ,'n'),
	);
	$similar = false;

	foreach ($similar_sets as $set) {
		if (in_array($a, $set) && in_array($b, $set)) {
			$similar = true;
		}
	}

	foreach ($similar_2chrs as $chars) {
		if ((in_array($a, $chars) && in_array($b.$bplus1, $set)) || (in_array($a.$aplus1, $chars) && in_array($b, $set))){
			$similar = true;
		}
	}

	return $similar;
}


?>
