<?php
    //? |-----------------------------------------------------------------------------------------------|
    //? |  /api/test/rank.php                                                                           |
    //? |                                                                                               |
    //? |  Copyright (c) 2018-2019 Belikhun. All right reserved                                         |
    //? |  Licensed under the MIT License. See LICENSE in the project root for license information.     |
    //? |-----------------------------------------------------------------------------------------------|

    require_once $_SERVER["DOCUMENT_ROOT"]."/lib/api_ecatch.php";
    require_once $_SERVER["DOCUMENT_ROOT"]."/lib/ratelimit.php";
    require_once $_SERVER["DOCUMENT_ROOT"]."/lib/belibrary.php";
    require_once $_SERVER["DOCUMENT_ROOT"]."/data/config.php";

    if ($config["publish"] !== true)
        stop(0, "Thành công!", 200, Array(
            "list" => Array(),
            "rank" => Array()
        ));

    require_once $_SERVER["DOCUMENT_ROOT"]."/data/xmldb/account.php";
    require_once $_SERVER["DOCUMENT_ROOT"]."/lib/logParser.php";

    $logdir = glob($config["logdir"] ."/*.log");
    $res = Array();
    $namelist = Array();

    foreach ($logdir as $i => $log) {
        $data = ((new logParser($log, LOGPARSER_MODE_MINIMAL)) -> parse())["header"];
        $filename = pathinfo($log, PATHINFO_FILENAME);
        $user = $data["user"];

        $namelist[$i] = $data["problem"];
        $res[$user]["status"][$data["problem"]] = $data["status"];
        $res[$user]["point"][$data["problem"]] = $data["point"];
        $res[$user]["logFile"][$data["problem"]] = ($config["viewlog"] === true) ? $filename : null;
        $res[$user]["username"] = $user;
        $res[$user]["name"] = getUserData($user)["name"] ?: null;

        if (!isset($res[$user]["total"]))
            $res[$user]["total"] = 0;
            
        $res[$user]["total"] += $data["point"];
    }

    if ($config["publish"] === true) {
        $nlr = arrayRemDub($namelist);
        $namelist = ((count($nlr) > 0) ? $nlr : Array());
    }

    usort($res, function($a, $b) {
        $a = $a["total"];
        $b = $b["total"];
    
        if ($a === $b)
            return 0;

        return ($a > $b) ? -1 : 1;
    });
    
    $returnData = Array (
        "list" => $namelist,
        "rank" => $res
    );

    $returnData["hash"] = md5(serialize($returnData));
    stop(0, "Thành công!", 200, $returnData);