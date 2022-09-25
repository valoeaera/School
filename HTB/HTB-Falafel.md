Name: Ben Roudebush
Lab: HTB - Falafel
IP Address: 10.129.1.83
Date: 25 September 2022

## High-Level Summary

Falafel is a Linux based machine with a fairly straight-forward solve path.  It starts with finding a login page. The credentials for a low-level user: `chris` can be discovered with SQL injection. PHP type juggling is hinted at by several things on the box and can be used to get a login as `admin`. With administrator access, a file upload can be used, in combination with unsafe filename truncation to upload and execute a PHP reverse shell. There are two users on the box: `moshe` and `yossi`.  Moshe's credentials are found in a configuration file and the user flag can be obtained. Yossi's password can be found in raw image data in a directory `moshe` has access to. `yossi` is a member of the `disk` group, which allows arbitrary file-read. This is used to steal the `root` SSH key and own the box.

## Service Enumeration

### Nmap

![nmap](../HTB-pics/falafel/01-nmap.png)

Nmap shows `SSH` and `HTTP` up on the typical port 22 and 80. This, along with the Apache header, indicates that the box is a Linux web server. It's always a good idea to take a look at the website, because they typically have a large attack surface.

### Web Enumeration

![port-80](../HTB-pics/falafel/02-port-80.png)

The website seems to be a social network for "falafel lovers". There's a fake email and a login button.

![login](../HTB-pics/falafel/03-login.png)

The typical `admin:admin` and other default credentials don't work. Interestingly enough, the page does give me different messages when valid and invalid usernames are given. With a valid username:

![valid-user](../HTB-pics/falafel/07-wrong-id.png)

With an invalid username:

![try-again](../HTB-pics/falafel/06-try-again.png)

I'll have to come back to this later when I have more information.

#### Directory Enumeration

![dir-fuzz](../HTB-pics/falafel/04-dir-fuzz.png)

Gobuster returns several pages. Of most interest here though is the non-standard `cyberlaw.txt` file.

![cyberlaw](../HTB-pics/falafel/05-cyberlaw.png)


## Initial Foothold

### SQL Injection

This file tells us the path to RCE and gives us the username of a low-level user named `chris`. Because of the verbose error messages, I have an inkling that this is some sort of custom login function. The different messages are likely a result of a SQL query that checks if there is a user with the supplied username:

```SQL
/* If returns 1: check password; IF returns 0: 'Try again.'*/
SELECT COUNT() FROM users WHERE username = [SUPPLIED_USERNAME]
```

I want to find out if this implementation is injectable, so I'll use SQLmap. I need a valid login request, so I'll intercept in Burp and copy to a file.

![Burp](../HTB-pics/falafel/10-request.png)

Then, I'll use SQLmap and look for the 'Wrong Identification' string from a correct username.

![SQLmap](../HTB-pics/falafel/11-sqlmap-find.png)

After a bit of a wait, SQLmap finds an injection on the username parameter.

![injection-found](../HTB-pics/falafel/12-sqlmap-found.png)

Next, I just want to dump the database to find anything useful. I could do this faster by selectively dumping what I need, but the easier method if you have a bit of time, is to just use `--dump` and dump the entire database. I was looking for a break for lunch, so I set the flag and left SQLmap running for a while.

![dump](../HTB-pics/falafel/13-sqlmap-dump.png)

It takes a bit, but SQLmap found 2 users' credentials and cracked one of the hashes to `juggling`.

![dump2](../HTB-pics/falafel/14-dumped.png)

I can log in with `chris:juggling`.

![dump2](../HTB-pics/falafel/15-login-as-chris.png)

The numerous references to 'juggling' and the note saying that `chris` could "log in as [the admin] without knowing [his] password" makes me think of a weird PHP (and some other languages) thing called "type juggling".

### Type Juggling -> admin login

#### Type Juggling Introduction

If you do any amount of coding, you may be familiar with the different types of 'equal signs':

| Symbol | Name                   | Meaning                                                                         |
| ------ | ---------------------- | ------------------------------------------------------------------------------- |
| `=`    | Assignment Operator    | "Assign the value on the right to the variable on the left."                    |
| `==`   | Equals Operator        | "Does the value on the left equal the value on the right?" (Type Juggling)      |
| `===`  | Strict Equals Operator | "Does the value *and* type on the left equal the value *and* type on the right" |

Essentially, in PHP (and some other languages) the `==` operator doesn't function how you would expect it to. 

```JS
// Returns TRUE
if ('3' == 3) {
	return true;
} else {
	return false;
}

// Returns FALSE
if ('3' === 3) {
	return true;
} else {
	return false;
}

// Returns TRUE
if ('0e1387158932' == 0) {
	return true;
}
```

The third case is of special interest to us here because of so-called [Magic Hashes](https://www.whitehatsec.com/blog/magic-hashes/). Essentially, with the `==`, PHP interprets `0e743...` as `0 * 10 ^ 743...`. This means that if we log in with a "Magic Hash" we are *way* more likely to get a hit than if we were using random guesses. If you'd like a more in-depth explanation of exactly why this works, read the above article.

#### Logging in with a Magic Hash

Using the hash starting with '2406...' from the article, I can log in to the site as admin.

![juggled](../HTB-pics/falafel/17-juggled.png)

There is a file upload via URL, which should come as no surprise given the .TXT file earlier. I tried some basic things to upload malicious things. But the checking seemed pretty buttoned-up. There is a quote on the profile that gives us a hint as to what to try next.

![hint-limits](../HTB-pics/falafel/18-hint-limits.png)

### Filename Truncation -> Shell as www-data

I set up a couple things to test my hunch. First, I'll need a web server to host the files I plan to upload to the target. I'll use the Python web server.

![web-server](../HTB-pics/falafel/19-web-server.png)

Here, you can see what happens normally, the target executes `wget` against my server and since `test.png` doesn't exist, gets a 404 in response.

![fine](../HTB-pics/falafel/20-file-test.png)

But, if I intercept this in Burp and change the filename to something ridiculous like 500 `V`s, then I get a different output.

![truncated](../HTB-pics/falafel/21-truncated.png)

Copying the name under the "New name is..." gives me a length of 236 characters.

![length](../HTB-pics/falafel/22-fle-name-length.png)

Now, I want the server to truncate something that ends in `.php.png` to `.php`. To do this, I'll first need a reverse shell to rename. I'll use the one that is included with Kali.

![rev-shell](../HTB-pics/falafel/23-get-php-rev.png)

Then, I will change the information to reflect my VPN IP and the port number of my listener.

![target](../HTB-pics/falafel/24-change-target.png)

Next, I set up a `nc` listener on port `42069`.

![listener](../HTB-pics/falafel/25-listener.png)

Now, I will make the name the exact length to get shortened to `.php`. Essentially, I want the un-shortened name to be 240 characters, so that when `.`, `p`,`n`, and `g` are the four letters removed. I actually did the simple subtraction wrong and had to take out a `V` later but forgot to redo the screenshots.

![name-change](../HTB-pics/falafel/26-change-name.png)

Then, I uploaded the file to the server and visited the directory that is listed in the command output. The page doesn't load, which is a good sign, since that means there is still a process running.

![long-URL](../HTB-pics/falafel/27-long-url.png)

Back on my listener, I have a shell as `www-data`.

![shell](../HTB-pics/falafel/28-shell.png)

### Shell as Moshe

In the web root directory, there is a `connection.php` file which defines the connection to the database. Inside this file is a password. Usually, if there is a password, it has been reused somewhere else. Anyone who tells you that they do not reuse passwords are either using a password manager, or they are trying to save face.

![web-directory](../HTB-pics/falafel/29-web-dir.png)

![hardcoded](../HTB-pics/falafel/30-hardcoded-creds.png)

There are two users with home directories on the box: `moshe` and `yossi`.

![users](../HTB-pics/falafel/31-users.png)

If I use the Python trick to get a "real shell", I can `su` to each of these users with the password. My first try, `moshe`, works.

![moshe](../HTB-pics/falafel/32-su-moshe.png)

The user flag is in `moshe`'s directory.

![user-flag](../HTB-pics/falafel/33-user-flag.png)

## Privilege Escalation

### Moshe -> Yossi

After I get a shell on a box, the first thing I usually do is run LinPEAS, this is just a way to save time as I *could* run these tests manually, but I have a job and school to do, so instead I use LinPEAS. I move the Python server to my `tools` directory and make a working directory on the target. Then I download the script using `wget`. I can set permissions and run the script.

![LinPEAS](../HTB-pics/falafel/34-linpeas.png)

The first thing I notice when running LinPEAS (other than the PolKit vulnerability because this box is old) is that `moshe` has a lot of groups.

![groups](../HTB-pics/falafel/35-user-info.png)

`yossi` is also currently logged in and using `bash`.

![yossi](../HTB-pics/falafel/36-yossi-bash.png)

I wanted to see what all files I could look at as a result of being in these groups, so I used this loop to record the filenames I had access to.

![copy-stuff](../HTB-pics/falafel/37-copy-stuff.png)


#### Reading Framebuffer

In `video`, I have access to `fb0` (framebuffer 0).

![fb0](../HTB-pics/falafel/38-video.png)

I can write the contents of the framebuffer to a file and get the resolution of the framebuffer.

![file-size](../HTB-pics/falafel/39-file-and-size.png)

I used `scp` with `moshe`'s credentials to download the file on my machine.

![file-transfer](../HTB-pics/falafel/40-file-trans.png)

I can open the file in `GIMP` to see what it is. I chose "RAW image data" as the file type.

![gimp](../HTB-pics/falafel/41-open-gimp.png)

If I mess with the image type and resolution, I can make out some commands with `yossi`'s password.

![image-manipulation](../HTB-pics/falafel/42-image-manip.png)

I can now use `ssh` as `yossi`.

![yossi-shell](../HTB-pics/falafel/43-yossi-shell.png)

### Yossi -> Root

I now run LinPEAS again with my new permissions.

![linpeas2](../HTB-pics/falafel/44-linpeas2.png)

`yossi` is a member of the `disk` group. This is [almost as good as](https://steflan-security.com/linux-privilege-escalation-exploiting-user-groups/#disk) `root` because I can read `/dev/sda1` as if I were `root`. 

![partitions](../HTB-pics/falafel/45-disk-group.png)

The following partitions are available to `yossi`:

![partitions](../HTB-pics/falafel/47-basically-root.png)

I can use `debugfs` to read and potentially even write restricted files. Here, I can read `/root/.ssh/id_rsa`.

![id-rsa](../HTB-pics/falafel/48-ez-win.png)

I can copy the key and `ssh` as `root`. I could've just read the root flag as `yossi`, but that's no fun!

![flag](../HTB-pics/falafel/49-root-flag.png)

## Vulnerability Summary

- Sensitive files available to unauthenticated users
	- The `cyberlaw.txt` file contains sensitive data about a security breach and gave me a valid username: `chris`.
- Injectable SQL queries
	- The login form performs a SQL query which I was able to inject into. This allowed me to access Chris's credentials.
- Unsafe PHP comparison
	- Use of the `==` operator instead of the `===` operator allowed me to access the administrator account without a valid password.
- Unsafe filename truncation
	- Because the truncation program doe snot re-append `.PNG`, I was able to upload PHP code and achieve RCE.
- Password reuse
	- `moshe`'s  password for SQL was reused as their system password.
- Password storage
	- `yossi`'s password was stored in the framebuffer. After realizing that they entered the set password command incorrectly, `yossi` should have chosen a different password.