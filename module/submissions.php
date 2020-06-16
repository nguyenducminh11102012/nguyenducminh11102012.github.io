<?php
	//? |-----------------------------------------------------------------------------------------------|
	//? |  /module/submissions.php                                                                      |
	//? |                                                                                               |
	//? |  Copyright (c) 2018-2020 Belikhun. All right reserved                                         |
	//? |  Licensed under the MIT License. See LICENSE in the project root for license information.     |
	//? |-----------------------------------------------------------------------------------------------|
	
	/**
	 * User's Submissions Manager Module
	 * 
	 * @package submissions
	 */

	require_once $_SERVER["DOCUMENT_ROOT"] ."/lib/belibrary.php";
	require_once $_SERVER["DOCUMENT_ROOT"] ."/module/config.php";
	
	define("SUBMISSIONS_DIR", getConfig("folders.submissions"));

	if (!file_exists(SUBMISSIONS_DIR))
		mkdir(SUBMISSIONS_DIR, 0777, true);

	function getSubmissionsList() {
		$dirs = glob(SUBMISSIONS_DIR ."/*", GLOB_ONLYDIR);
			
		foreach ($dirs as &$item)
			$item = pathinfo($item, PATHINFO_FILENAME);

		return $dirs;
	}

	function submissionExist($username) {
		return in_array($username, getSubmissionsList());
	}

	/**
	 * Submission Point v1
	 * 
	 * Thử Nghiệm
	 * @param	Float	$point		Điểm bài làm
	 * @param	Float	$time		Thời gian trong kì thi
	 * @param	Int		$subNth		Thứ hạng nộp bài
	 * @param	Int		$reSubNth	Số lần nộp lại bài
	 * @return	Float	Điểm SP
	 */
	function calculateSubmissionPoint(Float $point, Float $time, Int $subNth, Int $reSubmit) {
		// Time Graph
		// https://www.geogebra.org/graphing/gtcczbqu
		$timePoint = 0.2 + (0.8 * cos(((($time ** 0.5) * pi()) / 2) - (pi() / 2)) ** 2);

		// SubmitNth Graph
		// https://www.geogebra.org/graphing/e2tt3wab
		$subNth = 1 / $subNth;
		$submitNthPoint = 1 + ((($subNth ** 0.5) - 1) / (($subNth ** 6) + 2));

		// ReSubmit Graph
		// https://www.geogebra.org/graphing/kjywvjyp
		$reSubmitPoint = 1 / ($reSubmit ** 0.3);

		return Array(
			"point" => round($point * $timePoint * $submitNthPoint * $reSubmitPoint, 3),
			"detail" => Array(
				"time" => $timePoint,
				"submitNth" => $submitNthPoint,
				"reSubmit" => $reSubmitPoint
			)
		);
	}

	/**
	 * User's Submissions Manager Module
	 */
	class submissions {

		/**
		 * @param    String		$username	Username
		 */
		public function __construct(String $username) {
			$this -> username = $username;
			$this -> path = SUBMISSIONS_DIR ."/". $username;

			if (!file_exists($this -> path))
				mkdir($this -> path, 0777, true);
		}

		public function list() {
			$dirs = glob($this -> path ."/*", GLOB_ONLYDIR);
			
			foreach ($dirs as &$item)
				$item = pathinfo($item, PATHINFO_FILENAME);

			return $dirs;
		}

		private function __path(String $id) {
			return $this -> path ."/". strtolower($id);
		}

		private function submissionInit(String $id) {
			mkdir($this -> __path($id), 0777, true);

			(new fip($this -> __path($id) ."/meta.json", "{}")) -> write(Array(
				"id" => strtolower($id),
				"name" => $id,
				"username" => $this -> username,
				"createDate" => time(),
				"lastModify" => Array(
					"log" => null,
					"data" => null,
					"code" => null
				),
				"point" => null,
				"sp" => null,
				"statistic" => Array(
					"reSubmit" => 0,
					"remainTime" => null,
					"submitNth" => null
				),
				"codeFile" => null
			), "json");
		}

		public function exist(String $id) {
			return in_array($id, $this -> list());
		}

		public function getMeta(String $id) {
			return (new fip($this -> __path($id) ."/meta.json", "{}")) -> read("json");
		}

		public function updateMeta(String $id, Array $data = Array()) {
			$meta = $this -> getMeta($id);

			mergeObjectRecursive($meta, $data, false);
			(new fip($this -> __path($id) ."/meta.json", "{}")) -> write($meta, "json");
		}

		public function remove(String $id) {
			unlink($this -> __path($id));
		}

		//* ====== LOG FILE ======

		public function getLog(String $id) {
			if (!file_exists($this -> __path($id) ."/log.log"))
				return null;

			return (new fip($this -> __path($id) ."/log.log")) -> read();
		}

		public function saveLog(String $id, String $data) {
			if (!file_exists($this -> __path($id)))
				$this -> submissionInit($id);

			if (file_exists($data))
				rename($data, $this -> __path($id) ."/log.log");
			else
				(new fip($this -> __path($id) ."/log.log")) -> write($data);

			$this -> updateMeta($id, Array(
				"lastModify" => Array(
					"log" => time()
				)
			));
		}

		//* ====== PARSED DATA FILE ======

		public function getData(String $id) {
			if (!file_exists($this -> __path($id) ."/parsed.data"))
				return null;

			return (new fip($this -> __path($id) ."/parsed.data")) -> read("serialize");
		}

		public function saveData(String $id, Array $data) {
			if (!file_exists($this -> __path($id)))
				$this -> submissionInit($id);

			(new fip($this -> __path($id) ."/parsed.data")) -> write($data, "serialize");

			//? UPDATE META
			$meta = $this -> getMeta($id);

			$this -> updateMeta($id, Array(
				"lastModify" => Array(
					"data" => time()
				),
				"statistic" => Array(
					"reSubmit" => $meta["statistic"]["reSubmit"] + 1
				),

				"point" => $data["header"]["point"]
			));

			$globalModifyStream = new fip(SUBMISSIONS_DIR ."/modify.json", "{}");
			$globalModify = $globalModifyStream -> read("json");

			if (!isset($globalModify[$id]))
				$globalModify[$id] = Array();

			$globalModify[$id][$this -> username] = $meta["lastModify"]["code"] ?? time();
			arsort($globalModify, SORT_ASC);

			$globalModifyStream -> write($globalModify, "json");

			//? UPDATE SUBMISSION POINT FOR ALL USERS
			$beginTime = getConfig("time.contest.begin");
			$contestTime = $beginTime + (getConfig("time.contest.during") * 60) + getConfig("time.contest.offset");
			$remainTime = $contestTime - microtime(true);

			if ($remainTime > 0) {
				$lastm = 0;
				$rank = 0;

				foreach ($globalModify[$id] as $user => $modified) {
					if ($lastm !== $modified) {
						$rank++;
						$lastm = $modified;
					}

					$sub = new submissions($user);
					$meta = $sub -> getMeta($id);
					$sp = calculateSubmissionPoint(
						$meta["point"],
						($contestTime - $modified) / ($contestTime - $beginTime),
						$rank,
						$meta["statistic"]["reSubmit"]
					);

					$sub -> updateMeta($id, Array(
						"sp" => $sp,
						"statistic" => Array(
							"remainTime" => $remainTime,
							"submitNth" => $rank
						)
					));
				}
			}
		}

		//* ====== CODE FILE ======

		public function getCode(String $id) {
			$meta = $this -> getMeta($id);

			if (!$meta["codeFile"] || !file_exists($this -> __path($id) ."/". $meta["codeFile"]))
				return null;

			return (new fip($this -> __path($id) ."/". $meta["codeFile"])) -> read();
		}

		public function saveCode(String $id, String $data, String $extension = null) {
			if (!file_exists($this -> __path($id)))
				$this -> submissionInit($id);

			$codeFile = "code.". $extension;

			if (file_exists($data))
				copy($data, $this -> __path($id) ."/". $codeFile);
			else
				(new fip($this -> __path($id) ."/". $codeFile)) -> write($data);

			$this -> updateMeta($id, Array(
				"lastModify" => Array(
					"code" => time()
				),
				"codeFile" => $codeFile
			));
		}
	}