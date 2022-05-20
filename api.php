<?php

use LDAP\Result;

ini_set("memory_limit", "1024M");
set_time_limit(3600);
$stime = microtime(true);

$names = explode("|", str_replace("\n", "|", @$_REQUEST['names']));
$names = array_filter($names); // 移除空值

require_once "./include/functions.php";

if (@$_REQUEST['lang']) {
	$gui_lang_file = "./conf/lang/error_codes.".$_REQUEST['lang'].".php";
	if (file_exists($gui_lang_file)) {
		require_once "./conf/lang/error_codes.".$_REQUEST['lang'].".php";
	}
	else {
		require_once "./conf/lang/error_codes.php";
	}
}
else {
	require_once "./conf/lang/error_codes.php";
}

require_once "./include/queryNames.php";

$format = (!empty($_REQUEST['format']))?$_REQUEST['format']:'';
$against = (!empty($_REQUEST['source']))?$_REQUEST['source']:''; // source backbone 
$best = (!empty($_REQUEST['best']))?$_REQUEST['best']:'yes';
$ep = (!empty($_REQUEST['ep']))?$_REQUEST['ep']:file_get_contents(dirname(realpath(__FILE__)).'/conf/solr_endpoint'); // endpoint
$ep = trim($ep, " /\r\n");

$res = array();

// kim: 每一個輸入的name進行比對
foreach ($names as $nidx => $name) {

	// kim: 比對前去除掉特殊字元 & trim 空白字元，不保留subgenus的括號
	if (preg_match("/\p{Han}+/u", $name)){
		$name_cleaned = trim(preg_replace('/^[\pZ\pC]+|[\pZ\pC]+$/u','',$name), " \t\r\n.,;|");
	}
	else{
		$name_cleaned = canonical_form(trim(preg_replace('/^[\pZ\pC]+|[\pZ\pC]+$/u','',$name), " \t\r\n.,;|"), true);
	}

	// 如果可用空白鍵拆成array，則維持以原先的演算法match
	if (count(explode(" ", $name_cleaned)) > 1) {		
	/*
		for ($i=0; $i<strlen($name); $i++) {
			echo $name[$i].",".ord($name[$i]).",";
		}
	//*/
		$undecide = false;
		$onlyOne = false;
		$moreThan1 = false;
		$multiAnc = false;
		$multiNc = false;

		$scores = array();
		//if (empty($name)) continue;

		// kim: 進行比對
		$all_matched = queryNames($name, $against, $best, $ep);
			//echo '<pre>'.print_r($all_matched).'</pre>';exit();
		//ksort($all_matched);
		// kim: 比對後計算similarity
		foreach ($all_matched as $matched_name => $matched) {
			//var_dump($matched);
			//$scores[$matched_name] = nameSimilarity($matched_name, $name_cleaned, $matched['type']);
			$scores[$matched_name] = nameSimilarity($matched['matched_clean'], $name_cleaned, $matched['type']);
		}
		// kim: 根據score排序
		arsort($scores);

		// 如果選best, 要顯示所有最高同分的結果
		// 先計算有幾個best，for loop跑到該數字
		$arr1 = array();
		foreach($scores as $k=>$score)
		{
			$arr1[$k]    = (string) $score;
		}
		$score_vals = array_count_values($arr1);
		$num_highest_score = current($score_vals);
		$current_index = 0;

		foreach ($scores as $matched_name => $score) {
			if ($score < 0) { // kim: 最小為零
				$score = 0;
			}
			if ($best == 'yes') {
				$matched_only = array_keys($scores); // 名字拆成array
				$scores_only = array_values($scores);
				$score0 = $scores_only[0]; // kim: 最高分
				if (count($scores) > 1) {
					$moreThan1 = true;
					$score1 = $scores_only[1]; // kim: 第二高分
					//var_dump(array($score0, $score1));
					if (round($score0/3.5,3) == round($score1/3.5,3)) { // kim: 如果同分的話代表無法決定
						$undecide = true;
					}
					//var_dump($undecide);
				}
				else {
					$onlyOne = true;
				}

				if ($undecide || $onlyOne) {
					$comb = array();
					$comb_string = array();
					foreach ($matched_only as $m_idx => $mo) {
						$comb_dmin = 999;
						$comb[$m_idx] = array('[whatever]','[whatever]','[whatever]');
						$comb_string[$m_idx] = "";
						$comb_common = array();

						// matched name 和 search term
						$parts1 = explode(" ", canonical_form($mo, true));
						$parts2 = explode(" ", $name_cleaned);

						// 種小名 & 種下
						$parts_bc_1 = array_slice($parts1, 1);
						$parts_bc_2 = array_slice($parts2, 1);
						$diff_rank = false;
						foreach ($parts_bc_1 as $idx1 => $pbc1) {
							foreach ($parts_bc_2 as $idx2 => $pbc2) {
								// var_dump(levenshtein($pbc1, $pbc2));
								if (levenshtein($pbc1, $pbc2) < $comb_dmin) {
									// $comb = $parts1[0] . " " . $pbc1;
									$comb[$m_idx][0] = $parts1[0];
									$comb_common = array('idx' => $idx1, 'name' => $pbc1);
									$comb_dmin = levenshtein($pbc1, $pbc2);
									if ($idx1 != $idx2) {
										$diff_rank = true;
									}
								}
							}
						}
						$comb[$m_idx][$comb_common['idx']+1] = $comb_common['name'];
						$comb_string[$m_idx] = implode(" ", $comb[$m_idx]);
					}
					if ($moreThan1 || $diff_rank) {
						$undecide = true;
						$all_matched[$matched_name]['score'] = 'N/A';
						// $all_matched[$matched_name]['matched'] = implode("|", array_unique($comb_string));
						// echo $all_matched[$matched_name]['matched'];
						if ($moreThan1) {
							// 內容移除掉??
							// $all_matched[$matched_name]['namecode'] = array();
							// $all_matched[$matched_name]['accepted_namecode'] = array();
							// $all_matched[$matched_name]['source'] = array();
							$all_matched[$matched_name]['type'] .= " / Undecidable: Multiple cross-ranked matches";
						}
						elseif ($diff_rank) {
							$all_matched[$matched_name]['type'] .= " / Undecidable: Cross-ranked match";
						}
					}
				}
				$srcMatchedAncCnt = array(); // source matched accepted name code count
				$srcMatchedAnc = array(); // source matched accepted name code
				$srcAnc = array(); // source accepted name code
				// $all_matched[$matched_name]['best'] = array();
				if (!empty($all_matched[$matched_name]['accepted_namecode']) && !$undecide) {
	//var_dump($all_matched[$matched_name]);
					$ncs = $all_matched[$matched_name]['namecode'];
					$ancs = $all_matched[$matched_name]['accepted_namecode'];
					$srcs = $all_matched[$matched_name]['source'];
					foreach ($srcs as $src_idx => $src) {
						if ($ncs[$src_idx] === $ancs[$src_idx]) {
							$srcMatchedAncCnt[$src] += 1;
							$srcMatchedAnc[$src][] = $ancs[$src_idx];
						}
						else {
							$srcMatchedAncCnt[$src] += 0;
						}
						$srcAnc[$src][] = $ancs[$src_idx];
					}

					$max_count = 0;
					if (count($srcMatchedAncCnt) > 0) {					
						$original_type = $all_matched[$matched_name]['type'];
						$all_matched[$matched_name]['type'] = '';
						foreach ($srcMatchedAncCnt as $src => $srcMatchedAnc_cnt) {
							if ($srcMatchedAnc_cnt > 1) {
								// $all_matched[$matched_name]['score'] = 'N/A';
								$all_matched[$matched_name]['type'] .= $original_type.' / '."Undecidable: Multiple matched, accepted names|";
								$undecide = true;
							}
							elseif ($srcMatchedAnc_cnt == 0) {
								if (count(array_unique($srcAnc[$src])) > 1) {
									// $all_matched[$matched_name]['score'] = 'N/A';
									$all_matched[$matched_name]['type'] .= $original_type.' / '."Undecidable: Multiple accepted names of matched synonyms|";
									$undecide = true;
								}
								else {
									// $all_matched[$matched_name]['best'][$src] = $srcAnc[$src][0];
									$all_matched[$matched_name]['type'] .= $original_type .'|';
								}
							}
							else {
								// $all_matched[$matched_name]['best'][$src] = $srcMatchedAnc[$src][0];
								$all_matched[$matched_name]['type'] .= $original_type .'|';
							}
						}
					}
				}
			}
		
			// $all_matched[$matched_name]['taxonRank'] = detRank($all_matched[$matched_name]['matched'], $all_matched[$matched_name]['matched_clean']);
			$res[$nidx][] = array_merge(array('score' => round($score/3.5,3)), $all_matched[$matched_name]);
			if ($best == 'yes' &&  $current_index+1 == $num_highest_score) {
				break;
			}
			$current_index += 1;

		}
	}
	else {

		/**
		 * 單一字的比對，best與否只有差query語法
		 */
		$scores = array();

		$all_matched = queryNameSingle($name, $name_cleaned, $against, $best, $ep);
		// $all_matched = queryNameSingle('鐵杉', $against, 'yes', 'http://solr:8983/solr/taxa');
		// $name='鐵杉';
		// kim: 比對後計算similarity
		foreach ($all_matched as $matched_name => $matched) {
			if (preg_match("/\p{Han}+/u", $name)){
				$scores[$matched_name] = nameSimilaritySingle($matched['common_name'][0], $name_cleaned);
			}else{
				$scores[$matched_name] = nameSimilaritySingle($matched['matched_clean'], $name_cleaned);
			}
		}
		// kim: 根據score排序
		arsort($scores);

		// 如果選best, 要顯示所有最高同分的結果
		// 先計算有幾個best，for loop跑到該數字
		$arr1 = array();
		foreach($scores as $k=>$score)
		{
			$arr1[$k]    = (string) $score;
		}
		$score_vals = array_count_values($arr1);
		$num_highest_score = current($score_vals);
		$current_index = 0;

		foreach ($scores as $matched_name => $score) {

			if ($score < 0) { // kim: 最小為零
				$score = 0;
			}

			if ($score==1){
				$all_matched[$matched_name]['type'].='Full match';
			} elseif ($score < 1 and $matched_name != '') {
				$all_matched[$matched_name]['type'].='Fuzzy match';
			}			

			$srcMatchedAncCnt = array(); // source matched accepted name code count
			$srcMatchedAnc = array(); // source matched accepted name code
			$srcAnc = array(); // source accepted name code

			// 分數一樣且有多個結果
			if (!empty($all_matched[$matched_name]['accepted_namecode'])) {
				$ncs = $all_matched[$matched_name]['namecode'];
				$ancs = $all_matched[$matched_name]['accepted_namecode'];
				$srcs = $all_matched[$matched_name]['source'];
				foreach ($srcs as $src_idx => $src) {
					if ($ncs[$src_idx] === $ancs[$src_idx]) {
						$srcMatchedAncCnt[$src] += 1;
						$srcMatchedAnc[$src][] = $ancs[$src_idx];
					}
					else {
						$srcMatchedAncCnt[$src] += 0;
					}
					$srcAnc[$src][] = $ancs[$src_idx];
				}

				$max_count = 0;
				if (count($srcMatchedAncCnt) > 0) { 
					$original_type = $all_matched[$matched_name]['type'];
					$all_matched[$matched_name]['type'] = '';
					foreach ($srcMatchedAncCnt as $src => $srcMatchedAnc_cnt) {
						if ($srcMatchedAnc_cnt > 1) {
							$all_matched[$matched_name]['type'] .= $original_type.'/ '."Undecidable: Multiple matched, accepted names|";
							$undecide = true;
						}
						elseif ($srcMatchedAnc_cnt == 0) {
							if (count(array_unique($srcAnc[$src])) > 1) {
								$all_matched[$matched_name]['type'] .= $original_type.'/ '."Undecidable: Multiple accepted names of matched synonyms|";
								$undecide = true;
							} else {
								$all_matched[$matched_name]['type'] = $original_type .'|';
							}
						} else {
							$all_matched[$matched_name]['type'] .= $original_type .'|';
						}
					}
				}
			} 
		
			// $all_matched[$matched_name]['taxonRank'] = detRank($all_matched[$matched_name]['matched'], $all_matched[$matched_name]['matched_clean']);
			$res[$nidx][] = array_merge(array('score' => $score), $all_matched[$matched_name]);

			if ($best == 'yes' &&  $current_index+1 == $num_highest_score) {
				break;
			}

			$current_index += 1;
		}

	}
}

$etime = microtime(true);


render($res, $format, $etime - $stime, $best, $against);


function color_class ($idx) {
/*	$colors = array(
		'row_red',
		'row_orange',
		'row_yellow',
		'row_green',
		'row_blue',
		'row_purple',
	);
 */

	$colors = array(
		'row_yellow',
		'row_green',
	);
	return $colors[$idx % count($colors)];
}


function render_table ($data, $time, $hardcsv=false) {
	header("Content-type: text/html; charset=utf-8");
	$src_conf = read_src_conf();

	global $against, $best;

	$not_show = array(
		'name_cleaned',
		'url_id',
		'a_url_id',
		'best',
		'score',
		'simple_name'
	);

	echo "<head>";
	echo "<link href='http://fonts.googleapis.com/css?family=Roboto|Slabo+27px&subset=latin,latin-ext' rel='stylesheet' type='text/css'>";
	echo "<script src='https://code.jquery.com/jquery-2.1.4.min.js'></script>";
	echo "<link href='https://maxcdn.bootstrapcdn.com/bootswatch/3.3.7/cerulean/bootstrap.min.css' rel='stylesheet' integrity='sha384-zF4BRsG/fLiTGfR9QL82DrilZxrwgY/+du4p/c7J72zZj+FLYq4zY00RylP9ZjiT' crossorigin='anonymous'>";
	echo "<script src='https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js' integrity='sha384-Tc5IQib027qvyjSMfHjOMaLkfuWVxZxUPnCJA7l2mCWNIpG9mGCD8wGNIcPD7Txa' crossorigin='anonymous'></script>";
	echo "<script src='./js/diff.js'></script>";
	echo "</head>";

	$prev_name = "";
	$row_class = "even";

	$serial_no = 0;

	echo "<style>";
	echo "table, tr, td { border:solid 1px black;}\n";
	echo ".row_red { background:#FF9999;}\n";
	echo ".row_orange { background:#FFB547;}\n";
	echo ".row_yellow { background:#FFFF70;}\n";
	echo ".row_green { background:#8DE28D;}\n";
	echo ".row_blue { background:#91DAFF;}\n";
	echo ".row_purple { background:#E9A9FF;}\n";
	echo "tbody:nth-child(odd) {background: #F5F5F5;}\n";
	echo "td {vertical-align : middle !important;}\n";
	echo ".title td {text-align:center;}\n";
	echo "</style>";

	echo "<body>";
	echo "<div class='navbar navbar-fixed-top navbar-default'>";
	echo "<div class='container-fluid'>";
	echo "<div class='navbar-header'>";
	echo "<button type='button' class='navbar-toggle collapsed' data-toggle='collapse' data-target='#bs-example-navbar-collapse-2'>";
	echo "<span class='sr-only'>Toggle navigation</span>";
	echo "<span class='icon-bar'></span>";
	echo "<span class='icon-bar'></span>";
	echo "<span class='icon-bar'></span>";
	echo "</button>";
	echo "<a class='navbar-brand' href='index.html' style='color:#fff' >NomenMatch</a>";
	echo "</div>";
	echo "<div class='collapse navbar-collapse' id='bs-example-navbar-collapse-2'>";
	echo "<ul class='nav navbar-nav'>";
	echo "<li><a href='about.html'>About</a></li>";
	echo "<li><a href='index.html'>Match</a></li>";
	echo "<li><a href='howto.html'>How-To</a></li>";
	echo "<li><a href='api-doc.html'>API</a></li>";
	echo "</ul>";
	echo "</div>";
	echo "</div>";
	echo "</div><div class='container'><br/><br/><br/></div>";

	echo "<div class='container' style='margin-left:50px;'>";
	echo "<h1 class='navbar-brand m-b-0'>Matching results</h1>";
	echo "<p>";
	echo "query time: " . round($time, 3) . " s<br/>";
	echo "memory usage: " . round(memory_get_usage(true) / (1024 * 1024), 1) . " MB<br/>";
	echo "matched diff: <span style='color:blue;'>added</span> <span style='color:grey;'>common</span><br/>";
	echo "source: <span style='color:#DC143C;'>accepted</span> <span>invalid</span>";
	echo "</p>";
	
	echo "<table class='table table-bordered' style='width: 95vw'>";

	$tmp_data0 = $data[0][0];
	foreach ($not_show as $ns) {
		unset($tmp_data0[$ns]);
	}

	$columns = array_keys($tmp_data0);

	$titles = array('search<br/>term', 'matched<br/>clean', 'matched', 'common<br/>name','accepted<br/>namecode','namecode','source','kingdom',
					 'phylum','class','order','family','genus','taxon<br/>rank','match<br/>type');
	
	// 標題
	echo "<tbody><tr class='title'><td>" . implode("</td><td>", $titles) . "</td></tr></tbody>\n";
	// $prev_score = -100;
	unset($columns[0]); // name
	unset($columns[1]); // matched_clean
	unset($columns[14]); // type
	unset($columns[15]); // simple_name
	// 內文
	foreach ($data as $nidx => $name_d) {
		foreach ($name_d as $d) {

			$source_for_type = $d['source'];

			$ncs = $d['namecode'];
			$ancs = $d['accepted_namecode'];
			$sources = $d['source'];
			$url_ids = $d['url_id'];
			$aurl_ids = $d['a_url_id'];

			$html_ncs = array();
			$html_ancs = array();
			$html_sources = array();
			$url_anc_srcs = array();

			foreach ($sources as $src_idx => $src) {
				// TODO: this part must be implemented dynamicaly reading configurations from some file
				if (!empty($src_conf[$src]['url_base'])) {
					$url_base = $src_conf[$src]['url_base'];
				}
				else {
					$url_base = "http://example.org/species/id/";
				}

				$url = $url_base . $url_ids[$src_idx];
				$aurl = $url_base . $aurl_ids[$src_idx];

				$html_ncs[$src_idx] = "<a target='_blank' href='$url'>" . $ncs[$src_idx] . "</a>";
				if ($ancs[$src_idx]){
					$html_ancs[$src_idx] = "<a target='_blank' href='$aurl'>" . $ancs[$src_idx] ."</a>";
				} else {
					$html_ancs[$src_idx] = $ancs[$src_idx];
					$html_ncs[$src_idx] = $ncs[$src_idx];

				}
				$url_anc_srcs[$src][$ancs[$src_idx]] = "<a target='_blank' href='$aurl'>" . $ancs[$src_idx] ."</a>";

				if ($ncs[$src_idx] == $ancs[$src_idx]) {
					$html_sources[$src_idx] = "<font color='#DC143C'>$src</font>";
				}
				else {
					$html_sources[$src_idx] = $src;
				}
			}

			$d['namecode'] = $html_ncs;
			$d['accepted_namecode'] = $html_ancs;
			$d['source'] = $html_sources;

			echo "<tbody>";
			// $d 是每個matched_clean的集合
			$d['name'] = htmlentities($d['name']);
			$d['name'] = "<span name_cleaned='" . $d['name'] . "'>" . $d['name'] . "</span>";

			$rowspan = count($d['accepted_namecode']);

			// 第一行
			echo "<tr class='row_result' id='row_".$serial_no."'><td rowspan='".$rowspan."'>";
			echo $d['name']."</td>";
			echo "<td rowspan='".$rowspan."'>".$d['matched_clean']."</td>";
			foreach ($columns as $c) {
				if (($c == 'matched' && !preg_match("/\p{Han}+/u", $d['name']))|| ($c == 'common_name' && preg_match("/\p{Han}+/u", $d['name']))){
					echo "<td class='matched'>".$d[$c][0]."</td>";
				} else {
					echo "<td>".$d[$c][0]."</td>";
				}
			}

			$d['type'] = explode('|',$d['type']);
			$d['type'] = array_filter($d['type']); // 移除空值

			$source_count_values = array_count_values($source_for_type);
						
			if (count(array_unique($d['type'])) >1 && count(explode(" ", $d['matched_cleaned'])) == 1){
				echo "<td rowspan='".$source_count_values[$source_for_type[0]]."'>".$d['type'][0]."</td>";
			} else {
				echo "<td rowspan='".$rowspan."'>".$d['type'][0]."</td>";
			}
			echo "</tr>\n";

			// 其他行
			if ($rowspan > 1){
				foreach (range(1, $rowspan-1) as $n) {
					echo "<tr class='row_result' id='row_".$serial_no."'>";
					foreach ($columns as $c) {
						if ($c == 'matched'){
							echo "<td class='matched'>".$d[$c][$n]."</td>";
						} else {
							echo "<td>".$d[$c][$n]."</td>";
						}
					}
					// type
					if (count(array_unique($d['type'])) >1 && count(explode(" ", $d['matched_cleaned'])) == 1){
						$current_source_index = 0;

						if ($n == $source_count_values[$source_for_type[$current_source_index]]){
							$current_source_index += 1;
							echo "<td rowspan='".$source_count_values[$source_for_type[$current_source_index]]."'>".$d['type'][$current_source_index]."</td>";
						}
					}
					echo "</tr>\n";	
				}
				
			}
			echo "</tbody>";
		}
	}
	echo "</table>\n";
	echo "<script src='./js/diffName.js'></script>";

	echo "</div>";
	echo "</body>";
}


/*
function render_plain ($data, $time) {
	header("Content-type: text/plain; charset=utf-8");
	echo "query time: " . $time . "s\n";
	echo implode("\t", array_keys($data[0][0])) . "\n";
	foreach ($data as $d) {
		foreach ($d as $col) {
			foreach ($col as $idx => $val) {
				if (is_array($val)) {
					$new_val = implode("|", $val);
				}
				else {
					$new_val = implode("|", explode("|", trim($val, "\r\n ")));
				}
				$col[$idx] = $new_val;
			}
			echo implode("\t", $col) . "\n";
		}
	}
}
*/

function render_csv ($data) {
	header("Content-type: text/csv; charset=utf-8");
	header("Content-Disposition: attachment; filename=results.csv");
	header("Pragma: no-cache");
	header("Expires: 0");
	$utf8_bom = "\xEF\xBB\xBF"; 
	echo $utf8_bom;
	// echo "sep=\t\n";

	$header = array(
		'search_term',
		'matched_clean',
		'matched',
		'simple_name',
		'common_name',
		'accepted_namecode',
		'namecode',
		'source',
		'kingdom',
		'phylum',
		'class',
		'order',
		'family',
		'genus',
		'taxon_rank',
		'match_type');

	$columns = $header;
	unset($columns[15]); // match_type
	unset($columns[0]); // search_term
	unset($columns[1]); // matched_clean

	$results = array();
	array_push($results, $header);

	foreach($data as $d){
		foreach($d as $dsub){
			$types = explode('|',$dsub['type']);
			$types = array_filter($types); // 移除空值

			$source_for_type = $dsub['source'];
			$source_count_values = array_count_values($source_for_type);

			$tmp_keys = array_keys($dsub['matched']);
			foreach($tmp_keys as $k){
				$tmp = array();
				$tmp['search_term'] = $dsub['name'];
				$tmp['matched_clean'] = $dsub['matched_clean'];
				foreach($columns as $c){
					$tmp[$c] = $dsub[$c][$k];
				}

				$current_source_index = 0;

				if ($k == $source_count_values[$source_for_type[$current_source_index]]){
					$current_source_index += 1;
				}
				$tmp['match_type'] = $types[$current_source_index];
				array_push($results, $tmp);
			}
		}
	}

	$f = fopen('php://output', 'w');

    foreach ($results as $line) {
        fputcsv($f, $line, $delimiter=",");
    }
}

function render_json ($data, $time, $best, $against) {
	header('Content-Type: application/json; charset=utf-8');

	$columns = array(
		'matched',
		'simple_name',
		'common_name',
		'accepted_namecode',
		'namecode',
		'source',
		'kingdom',
		'phylum',
		'class',
		'order',
		'family',
		'genus',
		'taxon_rank');

	$results = array();

	foreach($data as $d){
		foreach($d as $dsub){
			$types = explode('|',$dsub['type']);
			$types = array_filter($types ); // 移除空值

			$source_for_type = $dsub['source'];
			$source_count_values = array_count_values($source_for_type);

			$tmp_array = array(
				'search_term' => $dsub['name'],
				'matched_clean' => $dsub['matched_clean']
			);
			$tmp_keys = array_keys($dsub['matched']);
			$tmp_results = array();
			foreach($tmp_keys as $k){
				$tmp = array();

				foreach($columns as $c){
					$tmp[$c] = $dsub[$c][$k];
				}

				$current_source_index = 0;

				if ($k == $source_count_values[$source_for_type[$current_source_index]]){
					$current_source_index += 1;
				}
				$tmp['match_type'] = $types[$current_source_index];
				array_push($tmp_results, $tmp);
			}
			$tmp_array['results'] = $tmp_results;
			array_push($results, $tmp_array);
		}
	}

	echo json_encode(array(
		'query' => array(
			'query_time' => $time,
			'best' => $best,
			'source' => $against,
			),
		'data' => $results,
		));
}


function render ($data, $format='table', $time, $best, $against) {

	$func_name = "render_" . $format;
	if (function_exists($func_name)) {
		call_user_func($func_name, $data, $time, $best, $against);
	}
	else {
		render_table($data, $time);
	}
}


// 計算score: 根據不同重要性給予權重
function nameSimilarity ($matched, $name, $type=null) {

	if ($matched == 'N/A') return 0;

	//$matched_cleaned = canonical_form($matched, true);
	$matched_cleaned = $matched;

	if (empty($matched_cleaned)) return 0;

	$score = 3;

	// kim: 比對後學名 & 原本輸入學名 拆成list
	$parts1 = explode(" ", $matched_cleaned);
	$parts2 = explode(" ", $name);

	if (count($parts1) === count($parts2)) { // kim: 相等的話 latin_genus, latin_s1, 其他rank等都相同
		// kim: 如果levenshtein距離小於3 or type是full match, score + 0.5
		if ((levenshtein($matched_cleaned, $name) <= 3)||(preg_match('/full/i', $type))) {
			$score += 0.5;
		}
		for ($pidx=0; $pidx<count($parts1); $pidx++) { // 如果排序不一樣, 扣1.5
			if ($parts1[$pidx][0] != $parts2[$pidx][0]) {
				$score -= 1.5;
			}
		}
	}

	// kim: 如果是sound or look like的匹配, 扣掉標準化後的levenshtein
	if (preg_match('/sound|look/i', $type)) {
		// $score -= 0.05;
		$score -= levenshtein($name, $matched_cleaned) / 20;
	}


	$penalty = 0;
	if (count($parts1) == count($parts2)) {
		$penalty = 0;
	}
	elseif (count(array_unique($parts1)) != count(array_unique($parts2))) {
		$penalty = 0.01;
	} 

	if (count(array_unique($parts1)) > count(array_unique($parts2))) {		
		$penalty += 0.5;
//		$penalty = $penalty / (3 - count(array_unique(array_slice($parts1, 1))));
	}
	elseif (count(array_unique($parts1)) < count(array_unique($parts2))) {
		if (count(array_unique($parts1)) < count($parts1)) {
			$penalty -= 0.0;
		}
		else {
			$penalty -= 0.2;
		}
//		$penalty = ($penalty < 0)?0:$penalty;
	}


	// 屬的比對, 加權*2
	$score -= (2 * levenshtein($parts1[0] /* Genus */, $parts2[0]) / strlen($parts1[0]));

	// 取得屬以下的array
	$sub_parts1 = array_slice($parts1, 1); 
	$sub_parts2 = array_slice($parts2, 1);

	$total_err = 0;
	$min_errs = array();
	foreach ($sub_parts2 as $sp2_idx => $sp2) {
//		$min_err = 999.0;
//		$min_errs[$sp1] = 999.0;
		if (is_null($min_errs[$sp2])) { 
			$min_errs[$sp2] = 999.0; // kim: error default = 999
		}
		foreach ($sub_parts1 as $sp1_idx => $sp1) {
			// kim: treat_word -> 可能互相替換的字視為minor error
			if ((levenshtein($sp1, $sp2) <= 3)&&(treat_word($sp1[0])==treat_word($sp2[0]))) {
//				echo "<xmp>$sp1 $sp2 ". levenshtein($sp1, $sp2) . "</xmp>";
				$tmp_err = (float) levenshtein($sp1, $sp2) / (float) strlen($sp1);
//				$min_err = min($min_err, $tmp_err);
				$min_errs[$sp2] = min($min_errs[$sp2], $tmp_err);
//				echo "$sp1, $sp2, $min_err, $total_err, $matched<br/>";
			}
			else {
//				$min_err = 0.5;
				if (count($sub_parts1) != count($sub_parts2)) {
					$factor = count($sub_parts1) + count($sub_parts2) - ($sp1_idx + $sp2_idx + 1);
				}
				else {
					$factor = 1;
				}
/*
echo "<xmp>";
var_dump(array($sp2, $sp1, $factor, min($min_errs[$sp2], 1 / $factor)));
echo "</xmp>";
//*/
				$min_errs[$sp2] = min($min_errs[$sp2], 1 / $factor);
/*
echo "<xmp>";
var_dump(array($sub_parts1, $sub_parts2));
echo "</xmp>";
//*/
			}

		}
	}
		/*
		echo "<xmp>";
		var_dump($min_errs);
		echo "</xmp>";
		//*/
	foreach (array_unique($sub_parts2) as $sp2) {
		$total_err += $min_errs[$sp2];
	}

//	$score -= (($total_err>1.5)?1.5:$total_err);
	$score -= $total_err;
		/*
		echo "<xmp>";
		var_dump(array($matched, $penalty, $score, $score - $penalty));
		echo "</xmp>";
		//*/
	return $score - $penalty;
}


// 種以上階層的score計算

function nameSimilaritySingle($matched_cleaned, $name){
	// $name= '鐵杉';
	// $common_name_array= explode(",", '臺灣鐵杉,油松,台灣鐵杉');

	if ($matched_cleaned == 'N/A' or empty($matched_cleaned)) {
		return 0;
	} elseif (preg_match("/\p{Han}+/u", $name)){ 
		// 如果是中文的話, 先把common_name_c(matched_clean)用逗號分隔,分別計算並取最高值
		$common_name_array = explode(",", $matched_cleaned);
		$common_name_score = array();
		foreach ($common_name_array as $cn) {
			$penalty = levenshtein($cn, $name) / max(strlen($cn), strlen($name));
			array_push($common_name_score, 1 - $penalty);
		}
		return max($common_name_score);
	}
	else {
		$penalty = levenshtein($matched_cleaned, $name) / max(strlen($matched_cleaned), strlen($name));
		return 1 - $penalty;
	} 
}

/** 
 *  @todo 這邊需不需要加上其他種下階層? or 改成直接從匯入的資料取得
*/
function detRank ($sciname, $sciname_clean) {
	$numParts = count(explode(" ", $sciname_clean));
	switch ($numParts) {
		case 2:
			return 'species';
			break;
		case 3:
			if (preg_match('/ var\.? /', $sciname)) {
				return 'variety';
			}
			else {
				return 'subspecies';
			}
			break;
		default:
			return 'unknown';
	}
}



function array_sort_by_column(&$arr, $col, $dir = SORT_STRING) {
    $sort_col = array();
    foreach ($arr as $key => $row) {
        $sort_col[$key] = $row[$col];
    }

    array_multisort($sort_col, $dir, $arr);
}



?>
