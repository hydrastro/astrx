<?php

class PostRedirectGet
{
    public function __construct()
    {
        $_SESSION["POST"] = $_POST;
        $target = "";
    }
}