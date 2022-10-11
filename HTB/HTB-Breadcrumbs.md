Name: Ben Roudebush

Lab: HTB - Breadcrumbs

IP Address: 10.129.229.19 // 10.129.229.24

Date: 10 October 2022

## High-Level Summary

Breadcrumbs is a fun Windows web server that showcases how powerful local file read vulnerabilities are, even if you can't just read `SSH` keys. The first bit of the lab is a lot of information gathering and web enumeration; the web page has a pretty massive attack surface and lots of things to look into. Eventually, I'll find a directory traversal vulnerability that lets me take a peek at the source code of the website. Using this, I can craft an administrator session cookie and `JWT` token. This gives me access to the upload feature, which I can combine with some obfuscation to get a web shell as `www-data`. Once I'm on the box, I can find some passwords stored in source code to first get a shell as `juliette` and then `SMB` access as `development`. In that share, there is source code for a password manager which is vulnerable to `SQL` injection which nets me the administrator password.

## Service Enumeration

### Nmap

![](../HTB-pics/breadcrumbs/01.png)
![](../HTB-pics/breadcrumbs/02.png)

Nmap returns a lot of ports. The main ones of interest are: 
- `MSRPC`, `NetBIOS`,  and`SMB`, which indicate that the operating system is Windows.
- `HTTP` and `HTTPS`, which is running `Apache`. That's unusual for Windows, as normally you'd see `IIS`.
- `SSH`, which is also unusual for a Windows box, but not unheard of in HTB.
- `MySQL`

### Web Enumeration

#### Manual Enumeration

`SMB` doesn't allow anonymous login, so I decided to just do some web enumeration.

![](../HTB-pics/breadcrumbs/03.png)

The page is a simple library landing page.

![](../HTB-pics/breadcrumbs/04.png)

Clicking on the `Check books.` button brings up this search window. You can search by `Title` and `Author`.

![](../HTB-pics/breadcrumbs/05.png)

The search seems to be matching on `OR`, instead of `AND` like you'd expect.

![](../HTB-pics/breadcrumbs/06.png)

Clicking on the orange `Book` button to the right of a book title generates the background pop-up with some book details and a blurb summarizing the plot. There was a `Checkout` button, but that just generated the foreground popup no matter which book is selected.

#### Gobuster

![](../HTB-pics/breadcrumbs/07.png)

`gobuster` shows me quite a few directories, we will return to these results quite often. First, I took a look at `/portal`.

#### Portal

![](../HTB-pics/breadcrumbs/08.png)

Visiting `/portal` gives me a login page. Trying the typical `admin:admin` and the like didn't yield a valid pair.

![](../HTB-pics/breadcrumbs/09.png)

Clicking the `helper` link in the alert gives me this list of `Current Helpers`. This is nice, as it might yield some valid usernames later.

![](../HTB-pics/breadcrumbs/10.png)

I messily copied the names to a file.

![](../HTB-pics/breadcrumbs/11.png)

Then used `awk` to clean them up.

![](../HTB-pics/breadcrumbs/12.png)

Going back to the login page, I can sign up for an account. I go ahead and do that with `valoe:valoeiscool`.

![](../HTB-pics/breadcrumbs/13.png)

When I click `Signup`, I get this dashboard where I can do a number of things. Clicking `Check tasks` gives me this tasklist which bleeds a lot of information about the implementation of the site.

![](../HTB-pics/breadcrumbs/14.png)

Firstly, I can see that the alert I was getting earlier was because the checkout feature is not ready yet. Secondly, it tells me that the `PHPSESSID` cookie is incorrectly configured, leading to infinite session duration. This is great because it might mean I use cookies to get an admin login without the username and password. Lastly, there seems to be something wrong with the logout feature. This probably won't affect me because I want to log *in*, not out, but it is something to keep in mind. Lots of information, but nothing that allows me to make significant progress. 

Going back to the portal, clicking `Order pizza` unfortunately alerts me that it's been disabled. Clicking `User management` gives me a list of users, including myself with my `Awaiting approval` status.

![](../HTB-pics/breadcrumbs/15.png)

This tells me the actual usernames of the people from the contact list, along with what their roles are. Trying to click `File management` doesn't do anything. I must have to have admin access to get it.

## Initial Foothold

### Local File Read

#### Finding a Directory Traversal

With my avenues exhausted on the Portal, I wanted to look at some of the other directories in the `gobuster` results.

![](../HTB-pics/breadcrumbs/07.png)

This time, I'll take a look at `/books`. When I visit the page, I get a directory listing.

![](../HTB-pics/breadcrumbs/21.png)

Clicking on the .HTML files gives the book summary pages from the search feature.

![](../HTB-pics/breadcrumbs/22.png)

This, along with one of the tasks from `/portal`, shows me that the book data isn't stored in a database, but in these HTML files. If I intercept the request generated by clicking `Book` on the search results in BurpSuite, I can see how the content is being generated.

![](../HTb-pics/breadcrumbs/23.png)

The file to be read is passed as a URL parameter, which could allow a directory traversal.

![](../HTB-pics/breadcrumbs/25.png)

Trying for `..\index.php` gives me the `PHP` code for the main page. The parameter is vulnerable to a directory traversal.

#### Forging an Admin Token

The original intercepted request is to `bookController.php`, I first want to see what that page does.

![](../HTB-pics/breadcrumbs/26.png)

The file defines a `MySQL` connection with some hard-coded credentials. I grab this and tidy it up in a file for later.

![](../HTB-pics/breadcrumbs/27.png)

The credentials don't work for the login to the portal or `SSH`. Instead, I'll take a look a the source for the login page:

![](../HTB-pics/breadcrumbs/32.png)

It doesn't yield much, but it does require `authController.php`. I can check that out too.

![](../HTB-pics/breadcrumbs/33.png)

It's a huge mess, but it yields a couple interesting things:

- It requires `cookie.php`, which I'll take a look at in a bit.
- It also sets a `JWT` token and gives me the secret key. I'll explain what this is next.

##### JWT

`JWT` is a nested acronym. It stands for `JSON Web Token`, which could also be interpreted as `JavaScript Object Notation Web Token`. Essentially, it's a cookie but a little more secure. It stores user identification information so that the server knows who's making a request, even if the session ID is stolen, because the `JWT` will still say (in this instance) `user: valoe`. So to log in as an admin, say, `paul`, I'd need:
- A valid session ID for `paul`
	- Conveniently enough, the task list earlier told me that the session IDs never expire.
- Paul's `JWT`

Luckily enough, I can use [jwt.io](http://jwt.io) to forge Paul's `JWT`, thanks to that handy-dandy secret key.

![](../HTB-pics/breadcrumbs/30.png)

I opened the `F12` menu on the portal and copied the token starting with `eyJ...`. I then pasted it in on the left hand side. Then, on the right hand side of the page, I pasted the secret key from `authController.php` into the `your-256-bit-secret` field.

![](../HTB-pics/breadcrumbs/31.png)

Then, I change the username in the `PAYLOAD: DATA` section of the right hand side to say `"username": "paul"` instead of `"username": "valoe"`. The left hand side changes. I've now successfully stolen Paul's `JWT` because the secret key was leaked and I knew his username!

##### PHPSESSID

Having the JWT is nice, but it won't get me logged in. For that, I'll need Paul's `PHPSESSID`.

![](../HTB-pics/breadcrumbs/34.png)

Using the directory traversal, I can see `cookie.php`, which generates the session IDs. The output here is gross, but the process itself is really simple.

```php
<?php

# Define a function named "makesession" that takes a parameter "username".
function makesession($username){

	# Set a maximum value equal to the index of the last letter of $username.
	$max = strlen($username) - 1;
	
	# Set a seed to determine which letter of the username is used as part of a salt.
	$seed = rand(0, $max);
	
	# Create a key. This is where the vulnerability lies.
	$key = "s4lTy_stR1nG_".$username[$seed]."(!528./9890";
	
	# Create a session cookie by hashing the above key.
	$session_cookie = $username.md5($key);
	
	return $session_cookie;
}

?>
```

So essentially, every time a user logs in, a session ID is created by hashing three bits of information:

- A static string `s4lTy_stR1nG_`
- One of the characters from the user's username
- And, to combat the infinite duration issue: a value that changes once a week

This means that within a week, a user has a number of possible cookies equal to the number of characters in their username and that session ID will be good for the duration of that week, until the third bit of info is changed. This is not *nearly* enough. Ideally, a user would get a new, random session ID each time they log in and every time they log out, that session is no longer valid. I added the following bit of code to the above file to generate a number of keys with my username as an example:

```php
# Generate 10 "random" cookies:
for ($val = 0; $val < 10; $val++) {
	$item_num = strval($val + 1);
	$cookie = strval(makesession("valoe"));
	echo "{$iter_num}: {$cookie}\n";
}
```

When I run a secure algorithm ten times, you would expect ten different results. Instead, when I run this code, I get a bunch of repeats because of the limited randomness of using one of five characters as a seed. If I ran this a hundred times, it would be even more pronounced with only five possible values.

![](../HTB-pics/breadcrumbs/36.png)

I modified the above code to create a generic version that systemically gets *every* possible cookie (by iterating through the characters instead of choosing a random one) for a given username. This code is also available in the `HTB-scripts` directory of my Git repository.

```php
<?php
/*
Written by Ben Roudebush, 10 October 2022
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
```

Running this with `paul` gives, as expected, four possible cookies.

![](../HTB-pics/breadcrumbs/38.png)

If I go back intercept the login request in Burp, I can try each of the four until I get a `200 OK` instead of `302 Found`. The last key here does the trick:

![](../HTB-pics/breadcrumbs/40.png)

I can go back to Firefox, open `F12` and set `PHPSESSID` to the generated value. When I refresh, I'm logged in as `paul`.

![](../HTB-pics/breadcrumbs/42.png)

### File Upload

I can finally click on `File management` and get an actual page. If I try to upload something naughty (like PHP code), I'm told I don't have permission. This is because, even though I'm logged in as `paul`, I haven't set the `JWT` yet. I can set it to the one I generated a bit ago and upload files. (I get a "Success!" message upon clicking `Upload`.)

![](../HTB-pics/breadcrumbs/43.png)

I whipped up this little PHP webshell. I have to use `shell_exec`, because Windows Defender is actually sort of doing its job for once.

![](../HTB-pics/breadcrumbs/44.png)

I have one last thing to do before I can just get RCE. If I try to upload the shell currently, the server appends `.zip` to the end and I can't execute it. So I intercept the upload request in Burp.

![](../HTB-pics/breadcrumbs/45.png)

Then change `valoe.zip` at the bottom to `valoe.php` and hit `Send`.

![](../HTB-pics/breadcrumbs/46.png)

Then, if I go to `10.129.229.19/portal/uploads/valoe.php?val=whoami`, I get the expected output from `whoami`.

![](../HTB-pics/breadcrumbs/47.png)

## Privilege Escalation

### Web Shell -> Shell as `www-data`

I want to get a real command line shell. To do that I need to get `nc64.exe` on the target so I can spawn a shell. I start a python web server locally in a directory with `nc64.exe`:

![](../HTB-pics/breadcrumbs/48.png)

Then, I go back to Burp Repeater and change the `whoami` command to instead download the file from this web server.

![](../HTB-pics/breadcrumbs/49.png)

Then, I start up an `nc` listener locally on an open port:

![](../HTB-pics/breadcrumbs/50.png)

Finally, I can call back to that shell from Burpsuite Repeater by executing `PowerShell` through `nc64.exe`. I use `CTRL + U` while highlighting the command to URL-encode it.

![](../HTB-pics/breadcrumbs/51.png)

I finally have a "real" shell on my listener:

![](../HTB-pics/breadcrumbs/52.png)

### `www-data` -> `juliette`

Looking up a directory from where my shell spawns, I can see a `pizzaDeliveryUserData` folder.

![](../HTB-pics/breadcrumbs/53.png)

Most of the files in here are disabled, but `juliette`'s has data.

![](../HTB-pics/breadcrumbs/54.png)

It's got a username and password in it.

![](../HTB-pics/breadcrumbs/55.png)

If I set `SSH` to not look for keys and instead ask for a password straight away, I can use this password to log in as `juliette`.

![](../HTB-pics/breadcrumbs/56.png)

### `juliette` -> `development`

The user flag is at `C:\Users\juliette\Desktop\user.txt`. There's also a `todo.html` file in the directory.

![](../HTB-pics/breadcrumbs/57.png)

#### StickyNotes

If I read the `todo.html` file, it tells me that there might be credentials stored in the StickyNotes application.

![](../HTB-pics/breadcrumbs/58.png)

[This article](https://www.techrepublic.com/article/how-to-backup-and-restore-sticky-notes-in-windows-10/) tells me where the StickyNotes data is stored. It's in this awful, unreadable path I never would have found without it.

![](../HTB-pics/breadcrumbs/60.png)

Within the directory, there are some `sqlite` files.

![](../HTB-pics/breadcrumbs/61.png)

I tried using `SCP` to download the files since I have the `SSH` session, but I couldn't get it to work. Instead, I used `impacket`'s `smbserver.py`.

![](../HTB-pics/breadcrumbs/62.png)

Then I just copied the files to the share from Windows.

![](../HTB-pics/breadcrumbs/63.png)

#### SQLite

I tidied up a little, then I listed some information about the database file.

![](../HTB-pics/breadcrumbs/65.png)

Here, I'm interested mainly in the actual contents of the sticky note: "Text".  I can grab that with a simple SQL query.

![](../HTB-pics/breadcrumbs/66.png)

The administrator credential has been moved of course, but I do get a new user: `development`.

### `development` -> `administrator`

#### SMB

I can't use `SSH` as `development`, but they can read `SMB` shares. It feels weird to be using `SMB` this late into a HTB box, but I digress.

![](../HTB-pics/breadcrumbs/67.png)

It looks like we have two shares: `Announcements` and `Development`. I'll check out `Development` first.

![](../HTB-pics/breadcrumbs/68.png)

There's one file, `Krypter_Linux`, which I'll grab to investigate.

#### Decompiling `Krypter_Linux`

If I try to just run the program, I get `No key supplied` after some usage help.

![](../HTB-pics/breadcrumbs/69.png)

If I supply some bogus key, I get an error that I'm missing the master key. Normally if I see this kind of thing in a HTB lab, it means I need to take a look at the source code of the program. I don't have the source, so I 'll have to use `ghidra`. I open up the program and analyze `Krypter`.

![](../HTB-pics/breadcrumbs/70.png)

This is doing something very unusual. The check for the master key simply looks for a value `local_lc` to match `0x641` (in base 10, that's 1601). The way it derives that value is by adding up the hexadecimal representation of each letter in a string and then comparing that to 1601. So *any* string whose letters when converted to hex sum up to 1601 will evaluate to true and run the command. While you're busy figuring that out, you'll likely (like I did) forget that all the code is doing is executing a curl command, which we can just emulate with a port forward.

#### SQL Injection

To use the same command as the program we have to have access to the target's port `1234`. The problem? It's only exposed to the target's `127.0.0.1`, we can get around this using an `SSH` tunnel to forward the target's port `1234` to our `localhost:1234`. We do this by going back to the `juliette` session and using [SSH Konami Code](https://www.sans.org/blog/using-the-ssh-konami-code-ssh-control-sequences/). Then running this command:

![](../HTB-pics/breadcrumbs/71.png)

Unfortunately, the box started boxing out pretty bad for me here. It shouldn't be anything to do with the port forward, but just know that you're not going crazy if it happens to you too. Anyway, we can run the same curl command that the program did and get an AES key.

![](../HTB-pics/breadcrumbs/72.png)

It's simply doing an SQL query, which we might be able to inject.

![](../HTB-pics/breadcrumbs/74.png)

If I try to list the database names via SQL injection, it works and I can see a database named `bread`. I do the following command to get the table names, of which there is one: `passwords`:

![](../HTB-pics/breadcrumbs/75.png)

If I try to use `SELECT *` here, it doesn't work because the program expects each array to have length of 1. I can get around this by concatenating the results I want together:

![](../HTB-pics/breadcrumbs/76.png)

#### CyberChef and Administrator Shell

The last step is cracking this hash. I'll use [CyberChef](https://gchq.github.io/CyberChef/), which has a great ability to fuzz around until you find the right combination for these more complex hashing functions. The derived "recipe":

- Converts the value from "password" (the value after the second `:`) from Base64 -> alphanumeric characters.
	- Placed in the top-right `Input` field
- Then does AES Decryption using:
	- The AES key (from either the intended function of the program or the value between the two `:`)
		- Select `LATIN1` from the input type to the right of the field
	- An Initialization Vector of at least 32 zeros
		- When the input type is set to `HEX`
	- And Input/output types of `raw:raw`
- To produce `p@ssw0rd!@#$9890./` as the administrator password
	- Placed in the bottom-right `Output` field

![](../HTB-pics/breadcrumbs/77.png)

I can log in via `SSH` as `administrator` with the same options as the `juliette` shell.

![](../HTB-pics/breadcrumbs/78.png)
![](../HTB-pics/breadcrumbs/79.png)

## Vulnerability Summary

- Local File Read
	- The request that reads the content of the book summaries has no input validation for which file to read. This allows the attacker to read the source code of the website.
	- Reading the source code discloses both the method by which the admin token is generated and the secret key for generating the `JWT`.
- Non-random `PHPSESSID` Generation
	- The method by which the `PHPSESSID` is generated is not sufficiently random and allows this value to be easily guessed.
- Too long `PHPSESSID`lifespan
	- The generated `PHPSESSID` is valid for up to a week. This is too long and allows anyone with knowledge of the token to reuse it.
- Lax Windows Defender protection
	- The webshell was able to be obfuscated enough to circumvent Windows Defender simply by changing the function used.
	- It is important to use the latest updates so as to have the most robust Windows Defender detection available, among other reasons.
- Hardcoded & Insufficient Credentials
	- `juliette`'s `SSH` password was stored in plaintext in a JSON file in the webpage's (publicly accessible) directory.
	- `development`'s `SMB` password was stored in plaintext in a sticky note.
	- The `Krypter_Linux` program did not adequately ensure the master key was correct before allowing the administrator's AES key to be read.
- SQL injection
	- The `Krypter_Linux` query was vulnerable to SQL injection despite SQL injection being patched on the Library Search (learned from the leaked source).