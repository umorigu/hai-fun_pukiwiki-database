<?php
/////////////////////////////////////////////////
// PukiWiki - Yet another WikiWikiWeb clone.
//
// $Id: make_link.php,v 1.50 2003/07/14 04:41:10 arino Exp $
//

// リンクを付加する
function make_link($string,$page = '')
{
	global $vars;
	static $converter;
	
	if (!isset($converter))
	{
		$converter = new InlineConverter();
	}
	$_converter = $converter; // copy
	return $_converter->convert($string, ($page != '') ? $page : $vars['page']);
}
//インライン要素を置換する
class InlineConverter
{
	var $converters; // as array()
	var $pattern;
	var $pos;
	var $result;
	
	function InlineConverter($converters=NULL,$excludes=NULL)
	{
		if ($converters === NULL)
		{
			$converters = array(
				'plugin',        // インラインプラグイン
				'note',          // 注釈 
				'url',           // URL
				'url_interwiki', // URL (interwiki definition)
				'mailto',        // mailto:
				'interwikiname', // InterWikiName
				'autolink',      // AutoLink
				'bracketname',   // BracketName
				'wikiname',      // WikiName
//				'rules',         // ユーザ定義ルール
			);
		}
		if ($excludes !== NULL)
		{
			$converters = array_diff($converters,$excludes);
		}
		$this->converters = array();
		$patterns = array();
		$start = 1;
		
		foreach ($converters as $name)
		{
			$classname = "Link_$name";
			$converter = new $classname($start);
			$pattern = $converter->get_pattern();
			if ($pattern === FALSE)
			{
				continue;
			}
			$patterns[] = "(\n$pattern\n)";
			$this->converters[$start] = $converter;
			$start += $converter->get_count();
			$start++;
		}
		$this->pattern = join('|',$patterns);
	}
	function convert($string,$page)
	{
		$this->page = $page;
		$this->result = array();
		
		$string = preg_replace_callback("/{$this->pattern}/x",array(&$this,'replace'),$string);
		
		$arr = explode("\x08",make_line_rules(htmlspecialchars($string)));
		$retval = '';
		while (count($arr))
		{
			$retval .= array_shift($arr).array_shift($this->result);
		}
		return $retval;
	}
	function replace($arr)
	{
		$obj = $this->get_converter($arr);
		
		$this->result[] = ($obj !== NULL and $obj->set($arr,$this->page) !== FALSE) ?
			$obj->toString() : make_line_rules(htmlspecialchars($arr[0]));
		
		return "\x08"; //処理済みの部分にマークを入れる
	}
	function get_objects($string,$page)
	{
		preg_match_all("/{$this->pattern}/x",$string,$matches,PREG_SET_ORDER);
		
		$arr = array();
		foreach ($matches as $match)
		{
			$obj = $this->get_converter($match);
			if ($obj->set($match,$page) !== FALSE)
			{
				$arr[] = $obj; // copy
			}
		}
		return $arr;
	}
	function &get_converter(&$arr)
	{
		foreach (array_keys($this->converters) as $start)
		{
			if ($arr[$start] != '')
			{
				return $this->converters[$start];
			}
		}
		return NULL;
	}
}
//インライン要素集合のベースクラス
class Link
{
	var $start;   // 括弧の先頭番号(0オリジン)
	var $text;    // マッチした文字列全体

	var $type;
	var $page;
	var $name;
	var $alias;

	// constructor
	function Link($start)
	{
		$this->start = $start;
	}
	// マッチに使用するパターンを返す
	function get_pattern()
	{
	}
	// 使用している括弧の数を返す ((?:...)を除く)
	function get_count()
	{
	}
	// マッチしたパターンを設定する
	function set($arr,$page)
	{
	}
	// 文字列に変換する
	function toString()
	{
	}
	
	//private
	// マッチした配列から、自分に必要な部分だけを取り出す
	function splice($arr)
	{
		$count = $this->get_count() + 1;
		$arr = array_splice($arr,$this->start,$count);
		while (count($arr) < $count)
		{
			$arr[] = '';
		}
		$this->text = $arr[0];
		return $arr;
	}
	// 基本パラメータを設定する
	function setParam($page,$name,$type='',$alias='')
	{
		static $converter = NULL;
		
		$this->page = $page;
		$this->name = $name;
		$this->type = $type;
		if ($type != 'InterWikiName' and preg_match('/\.(gif|png|jpe?g)$/i',$alias))
		{
			$alias = htmlspecialchars($alias);
			$alias = "<img src=\"$alias\" alt=\"$name\" />";
		}
		else if ($alias != '')
		{
			if ($converter === NULL)
			{
				$converter = new InlineConverter(array('plugin'));
			}
			$alias = make_line_rules($converter->convert($alias,$page));
		}
		$this->alias = $alias;
		
		return TRUE;
	}
}
// インラインプラグイン
class Link_plugin extends Link
{
	var $param,$body,$plain;
	
	function Link_plugin($start)
	{
		parent::Link($start);
	}
	function get_pattern()
	{
		return <<<EOD
&
(      # (1) plain
 (\w+) # (2) plugin name
 (?:
  \(
   ((?:(?!\)[;{]).)*) # (3) parameter
  \)
 )?
)
(?:
 \{
  (.*) # (4) body
 \}
)?
;
EOD;
	}
	function get_count()
	{
		return 4;
	}
	function set($arr,$page)
	{
		$arr = $this->splice($arr);
		
		$this->plain = $arr[1];
		$name = $arr[2];
		$this->param = $arr[3];
		$this->body = ($arr[4] == '') ? '' : make_link($arr[4]);
		
		return parent::setParam($page,$name,'plugin');
	}
	function toString()
	{
		return $this->make_inline($this->plain,$this->name,$this->param,$this->body);
	}
	function make_inline($plain,$func,$param,$body)
	{
		//&hoge(){...}; &fuga(){...}; のbodyが'...}; &fuga(){...'となるので、前後に分ける
		$after = '';
		if (preg_match("/^ ((?!};).*?) }; (.*?)  &amp; ( (\w+) (?: \( ((?:(?!\)[;{]).)*) \) )? ) { (.+)$/x",$body,$matches))
		{
			$body = $matches[1];
			$after = $matches[2].$this->make_inline($matches[3],$matches[4],$matches[5],$matches[6]);
		}
		
		// プラグイン呼び出し
		if (exist_plugin_inline($func))
		{
			$str = do_plugin_inline($func,$param,$body);
			if ($str !== FALSE) //成功
			{
				return $str.$after;
			}
		}
		
		// プラグインが存在しないか、変換に失敗
		return make_line_rules(htmlspecialchars('&'.$plain).($body == '' ? ';' : "\{$body};")).$after;
	}
}
// 注釈
class Link_note extends Link
{
	function Link_note($start)
	{
		parent::Link($start);
	}
	function get_pattern()
	{
		return <<<EOD
\(\(    # open paren
 (      # (1) note body
  (?:
   (?>  # once-only 
    (?:
     (?!\(\()(?!\)\)(?:[^\)]|$)).
    )+
   )
   |
   (?R) # or recursive of me
  )*
 )
\)\)
EOD;
	}
	function get_count()
	{
		return 1;
	}
	function set($arr,$page)
	{
		global $foot_explain;
		static $note_id = 0;
		
		$arr = $this->splice($arr);
		
		$id = ++$note_id;
		$note = make_link($arr[1]);
		
		$foot_explain[$id] = <<<EOD
<a id="notefoot_$id" href="#notetext_$id" class="note_super">*$id</a>
<span class="small">$note</span>
<br />
EOD;
		$name = "<a id=\"notetext_$id\" href=\"#notefoot_$id\" class=\"note_super\">*$id</a>";
		
		return parent::setParam($page,$name);
	}
	function toString()
	{
		return $this->name;
	}
}
// url
class Link_url extends Link
{
	function Link_url($start)
	{
		parent::Link($start);
	}
	function get_pattern()
	{
		$s1 = $this->start + 1;
		return <<<EOD
(\[\[            # (1) open bracket
 ([^\]]+)(?:>|:) # (2) alias
)?
(                # (3) url
 (?:https?|ftp|news):\/\/[!~*'();\/?:\@&=+\$,%#\w.-]+
)
(?($s1)\]\])     # close bracket
EOD;
	}
	function get_count()
	{
		return 3;
	}
	function set($arr,$page)
	{
		$arr = $this->splice($arr);
		
		$name = htmlspecialchars($arr[3]);
		$alias = ($arr[2] == '') ? $name : $arr[2];
		return parent::setParam($page,$name,'url',$alias);
		
	}
	function toString()
	{
		return "<a href=\"{$this->name}\">{$this->alias}</a>";
	}
}
// url (InterWiki definition type)
class Link_url_interwiki extends Link
{
	function Link_url_interwiki($start)
	{
		parent::Link($start);
	}
	function get_pattern()
	{
		return <<<EOD
\[       # open bracket
(        # (1) url
 (?:(?:https?|ftp|news):\/\/|\.\.?\/)[!~*'();\/?:\@&=+\$,%#\w.-]*
)
\s
([^\]]+) # (2) alias
\]       # close bracket
EOD;
	}
	function get_count()
	{
		return 2;
	}
	function set($arr,$page)
	{
		$arr = $this->splice($arr);
		
		$name = htmlspecialchars($arr[1]);
		$alias = $arr[2];
		
		return parent::setParam($page,$name,'url',$alias);
	}
	function toString()
	{
		return "<a href=\"{$this->name}\">{$this->alias}</a>";
	}
}
//mailto:
class Link_mailto extends Link
{
	var $is_image,$image;
	
	function Link_mailto($start)
	{
		parent::Link($start);
	}
	function get_pattern()
	{
		$s1 = $this->start + 1;
		return <<<EOD
(?:\[\[([^\]]+)(?:>|:))?   # (1) alias
 ([\w.-]+@[\w-]+\.[\w.-]+) # (2) mailto
(?($s1)\]\])               # close bracket if (1)
EOD;
	}
	function get_count()
	{
		return 2;
	}
	function set($arr,$page)
	{
		$arr = $this->splice($arr);
		
		$name = $arr[2];
		$alias = ($arr[1] == '') ? $arr[2] : $arr[1];
		
		return parent::setParam($page,$name,'mailto',$alias);
	}
	function toString()
	{
		return "<a href=\"mailto:{$this->name}\">{$this->alias}</a>";
	}
}
//InterWikiName
class Link_interwikiname extends Link
{
	var $url = '';
	var $param = '';
	var $anchor = '';
	
	function Link_interwikiname($start)
	{
		parent::Link($start);
	}
	function get_pattern()
	{
		$s1 = $this->start + 1;
		$s3 = $this->start + 3;
		$s5 = $this->start + 5;
		return <<<EOD
\[\[                    # open bracket
(?:
 (\[\[)?                # (1) open bracket
 ((?:(?<!\]\]).)+)>     # (2) alias
)?
(?:
 (\[\[)?                # (3) open bracket
 ((?:(?!\s|:|\]\]).)+)  # (4) InterWiki
 (                      # (5)
  (?($s1)\]\]           #  close bracket if (1)
  |(?($s3)\]\])         #   or (3)
  )
 )?
 (?<! > | >\[\[ )       # not '>' or '>[['
 \:((?:(?<!>|\]\]).)+)  # (6) param
 (?($s5) |              # if !(5)
  (?($s1)\]\]           #  close bracket if (1)
  |(?($s3)\]\])         #   or (3)
  )
 )
)?
\]\]                    # close bracket
EOD;
	}
	function get_count()
	{
		return 6;
	}
	function set($arr,$page)
	{
		global $script;
		
		$arr = $this->splice($arr);
		
		$this->param = $arr[6];
		if (preg_match('/^([^#]+)(#[A-Za-z][\w-]*)$/',$arr[6],$matches))
		{
			$this->anchor = $matches[2];
			$this->param = $matches[1];
		}
		$name = htmlspecialchars($arr[4].':'.$this->param);
		$alias = ($arr[2] != '') ? $arr[2] : $arr[4].':'.$arr[6];
		$this->url = get_interwiki_url($arr[4],$this->param);
		if ($this->url === FALSE)
		{
			$this->url = $script.'?'.rawurlencode('[['.$arr[4].':'.$this->param.']]');
		}
		
		return parent::setParam($page,$name,'InterWikiName',$alias);
	}
	function toString()
	{
		return "<a href=\"{$this->url}{$this->anchor}\" title=\"{$this->name}\">{$this->alias}</a>";
	}
}
// BracketName
class Link_bracketname extends Link
{
	var $anchor,$refer;
	
	function Link_bracketname($start)
	{
		parent::Link($start);
	}
	function get_pattern()
	{
		global $WikiName,$BracketName;
		
		$s1 = $this->start + 1;
		$s3 = $this->start + 3;
		$s7 = $this->start + 7;
		return <<<EOD
\[\[                     # open bracket
(?:
 (\[\[)?                 # (1) open bracket
 ((?:(?<!\]\]).)+)>      # (2) alias
)?
(\[\[)?                  # (3) open bracket
(                        # (4) PageName
 ($WikiName)             # (5) WikiName
 |
 ($BracketName)          # (6) BracketName
)?
(                        # (7)
 (?($s1)\]\]             #  close bracket if (1)
  |(?($s3)\]\])          #   or (3)
 )
)
(\#(?:[a-zA-Z][\w-]*)?)? # (8) anchor
(?($s7)|                 # if !(7)
 (?($s1)\]\]             #  close bracket if (1)
  |(?($s3)\]\])          #   or (3)
 )
)
\]\]                     # close bracket
EOD;
	}
	function get_count()
	{
		return 8;
	}
	function set($arr,$page)
	{
		global $WikiName;
		
		$arr = $this->splice($arr);
		
		$alias = $arr[2];
		$name = $arr[4];
		$this->anchor = $arr[8];
		
		if ($name == '' and $this->anchor == '')
		{
			return FALSE;
		}
		if ($name != '' and preg_match("/^$WikiName$/",$name))
		{
			return parent::setParam($page,$name,'pagename',$alias);
		}
		if ($alias == '')
		{
			$alias = $name.$this->anchor;
		}
		if ($name == '')
		{
			if ($this->anchor == '')
			{
				return FALSE;
			}
		}
		else
		{
			$name = get_fullname($name,$page);
			if (!is_pagename($name))
			{
				return FALSE;
			}
		}
		return parent::setParam($page,$name,'pagename',$alias);
	}
	function toString()
	{
		return make_pagelink(
			$this->name,
			$this->alias,
			$this->anchor,
			$this->page
		);
	}
}
// WikiName
class Link_wikiname extends Link
{
	function Link_wikiname($start)
	{
		parent::Link($start);
	}
	function get_pattern()
	{
		global $WikiName,$nowikiname;
		
		return $nowikiname ? FALSE : "($WikiName)";
	}
	function get_count()
	{
		return 1;
	}
	function set($arr,$page)
	{
		$arr = $this->splice($arr);
		$name = $alias = $arr[0];
		return parent::setParam($page,$name,'pagename',$alias);
	}
	function toString()
	{
		return make_pagelink(
			$this->name,
			$this->alias,
			'',
			$this->page
		);
	}
}
// AutoLink
class Link_autolink extends Link
{
	var $forceignorepages = array();
	
	function Link_autolink($start)
	{
		parent::Link($start);
	}
	function get_pattern()
	{
		global $autolink;
		static $auto,$forceignorepages;
		
		if (!$autolink or !file_exists(CACHE_DIR.'autolink.dat'))
		{
			return FALSE;
		}
		if (!isset($auto)) // and/or !isset($forceignorepages)
		{
			@list($auto,$forceignorepages) = file(CACHE_DIR.'autolink.dat');
			$forceignorepages = explode("\t",$forceignorepages);
		}
		$this->forceignorepages = $forceignorepages;
		return "($auto)";
	}
	function get_count()
	{
		return 1;
	}
	function set($arr,$page)
	{
		global $WikiName;
		
		$arr = $this->splice($arr);
		$name = $alias = $arr[0];
		// 無視リストに含まれている、あるいは存在しないページを捨てる
		if (in_array($name,$this->forceignorepages) or !is_page($name))
		{
			return FALSE;
		}
		return parent::setParam($page,$name,'pagename',$alias);
	}
	function toString()
	{
		return make_pagelink(
			$this->name,
			$this->alias,
			'',
			$this->page
		);
	}
}
// ユーザ定義ルール
/*
class Link_rules extends Link
{
	var $replaces;
	var $count;
	
	function Link_rules($start)
	{
		parent::Link($start);
	}
	function get_pattern()
	{
		global $line_rules;
		
		$rules = array();
		$this->replaces = array();
		$this->count = 0;
		
		foreach ($line_rules as $pattern=>$replace)
		{
			$rules[] = "($pattern)";
			$this->replaces[++$this->count] = $replace;
			if (preg_match_all('/\$\d/',$replace,$matches,PREG_SET_ORDER))
			{
				$this->count += count($matches);
			}
		}
		$this->replaces[++$this->count] = ''; // sentinel
		return join("|",$rules);
	}
	function get_count()
	{
		return $this->count;
	}
	function set($arr,$page)
	{
		$arr = $this->splice($arr);
		
		$name = $arr[0];
		reset($this->replaces);
		while (list($start,$replace) = each($this->replaces))
		{
			if ($replace == '')
			{
				$name = htmlspecialchars($name);
				break;
			}
			if (!array_key_exists($start,$arr) or $arr[$start] == '')
			{
				continue;
			}
			list($end,$dummy) = each($this->replaces);
			$count = $end - $start;
			$_arr = array_splice($arr,$start,$count);
			$name = $replace;
			for ($n = 1; $n < $count; $n++)
			{
				$name = str_replace('$'.$n,make_link($_arr[$n]),$name);
			}
			break;
		}
		return parent::setParam($page,$name,'rule','');
	}
	function toString()
	{
		return $this->name;
	}
}
*/
// ページ名のリンクを作成
function make_pagelink($page,$alias='',$anchor='',$refer='')
{
	global $script,$vars,$show_title,$show_passage,$link_compact,$related;
	global $_symbol_noexists;
	
	$s_page = htmlspecialchars(strip_bracket($page));
	$s_alias = ($alias == '') ? $s_page : $alias;
	
	if ($page == '')
	{
		return "<a href=\"$anchor\">$s_alias</a>";
	}
	
	$r_page = rawurlencode($page);
	$r_refer = ($refer == '') ? '' : '&amp;refer='.rawurlencode($refer);
	
	if (!array_key_exists($page,$related) and $page != $vars['page'] and is_page($page))
	{
		$related[$page] = get_filetime($page);
	}
	
	if (is_page($page))
	{
		$passage = get_pg_passage($page,FALSE);
		$title = $link_compact ? '' : " title=\"$s_page$passage\"";
		return "<a href=\"$script?$r_page$anchor\"$title>$s_alias</a>";
	}
	else
	{
		$retval = "$s_alias<a href=\"$script?cmd=edit&amp;page=$r_page$r_refer\">$_symbol_noexists</a>";
		if (!$link_compact)
		{
			$retval = "<span class=\"noexists\">$retval</span>";
		}
		return $retval;
	}
}
// 相対参照を展開
function get_fullname($name,$refer)
{
	global $defaultpage;
	
	if ($name == '')
	{
		return $refer;
	}
	
	if ($name{0} == '/')
	{
		$name = substr($name,1);
		return ($name == '') ? $defaultpage : $name;
	}
	
	if ($name == './')
	{
		return $refer;
	}
	
	if (substr($name,0,2) == './')
	{
		$arrn = preg_split('/\//',$name,-1,PREG_SPLIT_NO_EMPTY);
		$arrn[0] = $refer;
		return join('/',$arrn);
	}
	
	if (substr($name,0,3) == '../')
	{
		$arrn = preg_split('/\//',$name,-1,PREG_SPLIT_NO_EMPTY);
		$arrp = preg_split('/\//',$refer,-1,PREG_SPLIT_NO_EMPTY);
		
		while (count($arrn) > 0 and $arrn[0] == '..')
		{
			array_shift($arrn);
			array_pop($arrp);
		}
		$name = count($arrp) ? join('/',array_merge($arrp,$arrn)) :
			(count($arrn) ? "$defaultpage/".join('/',$arrn) : $defaultpage);
	}
	return $name;
}

// InterWikiNameを展開
function get_interwiki_url($name,$param)
{
	global $interwiki;
	static $interwikinames;
	
	if (!isset($interwikinames))
	{
		$interwikinames = array();
		foreach (get_source($interwiki) as $line)
		{
			if (preg_match('/\[((?:(?:https?|ftp|news):\/\/|\.\.?\/)[!~*\'();\/?:\@&=+\$,%#\w.-]*)\s([^\]]+)\]\s?([^\s]*)/',$line,$matches))
			{
				$interwikinames[$matches[2]] = array($matches[1],$matches[3]);
			}
		}
	}
	if (!array_key_exists($name,$interwikinames))
	{
		return FALSE;
	}
	list($url,$opt) = $interwikinames[$name];
	
	// 文字エンコーディング
	switch ($opt)
	{
		// YukiWiki系
		case 'yw':
			if (!preg_match("/$WikiName/",$param))
			{
				$param = '[['.mb_convert_encoding($param,'SJIS',SOURCE_ENCODING).']]';
			}
			break;
		
		// moin系
		case 'moin':
			$param = str_replace('%','_',rawurlencode($param));
			break;
		
		// 内部文字エンコーディングのままURLエンコード
		case '':
		case 'std':
			$param = rawurlencode($param);
			break;
		
		// URLエンコードしない
		case 'asis':
		case 'raw':
			break;
		
		default:
			// エイリアスの変換
			if (array_key_exists($opt,$encode_aliases))
			{
				$opt = $encode_aliases[$opt];
			}
			// 指定された文字コードへエンコードしてURLエンコード
			$param = rawurlencode(mb_convert_encoding($param,$opt,'auto'));
	}
	
	// パラメータを置換
	if (strpos($url,'$1') !== FALSE)
	{
		$url = str_replace('$1',$param,$url);
	}
	else
	{
		$url .= $param;
	}
	
	return $url;
}
?>
