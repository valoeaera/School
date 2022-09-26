Name: Ben Roudebush

Lab: HTB - Dab

IP Address: 10.129.227.201

Date: 29 August 2022

## High-Level Summary

Dab is a Linux-based web server with `FTP`, `SSH`, and two different `HTTP` ports open. `FTP` only shares a simple image. The port 80 service has a simple log-in page, which can be fuzzed to gain credentials `admin:Password1`. The administrator panel is mostly static, with no interactive elements. The port 8080 page informs the user that a cookie isn't set, which can also be fuzzed to a value of `secret`. With this cookie set, the page returns a form for sending TCP data. Open ports can be fuzzed to find port 11211, `memcache` is open. The `memcache` slabs can be dumped to reveal a hash for the `genevieve` user. This has can be cracked to `Princess1` and used to log in via `ssh`.  Genevieve has SUID permissions on several binaries, which can be poisoned to achieve `root` permissions and own the box.



## Service Enumeration

### Nmap

![nmap](../HTB-pics/dab/01-nmap.png)

Nmap shows `FTP`, `SSH`, and two `HTTP` ports open on ports 21, 22, 80, and 8080 respectively. The web pages are running the same version of `nginx`, so they are likely on the same host. I started by inspecting `FTP` for anonymous login.

### FTP Enumeration

![ftp](../HTB-pics/dab/02-ftp.png)

Anonymous login is enabled and a file is available. Unfortunately, it is simply an image of someone "dabbing".

![dab](../HTB-pics/dab/03-dab.png)

I moved on to investigate the `HTTP` services, as webpages typically have a wide range of attack vectors.

### Web Enumeration

![login](../HTB-pics/dab/04-login.png)

The port 80 site is just a login page. Typical default credentials such as `admin:admin` don't work, so I decided to move on to port 8080 before throwing `hydra` at it.

![cookie](../HTB-pics/dab/05-cookie.png)

The port 8080 site informs me that the proper authentication cookie is not set. Since I was at a dead-end on both web pages, I moved on to fuzzing both sites for valid credentials.

#### Credential Fuzzing

I used BurpSuite to intercept a login request for the port 80 site to see how the request is formatted.

![request](../HTB-pics/dab/06-request.png)

Now, `wfuzz` can be used to guess the credentials. I assumed `admin` was the username and fed the Dark Web Top 1000 (from SecLists) passwords into `wfuzz`. Normally, you'd run this command twice: once with no `--hx` tag to see what size an invalid credential pair returns, then another time with the appropriate `hx` tag to hide invalid responses. For demonstration purposes, I'll just show the second command here.

![fuzz](../HTB-pics/dab/07-fuzz.png)

I get a hit on `admin:Password1`, very secure. Logging in with these credentials nets a static page with what looks to be the output of some command. I looked for ways to influence what commands were executed, but none were presented here.

![stock](../HTB-pics/dab/08-stock.png)

In the comments of the source though, there is this note when the page first loads, indicating that the data is pulled from the database.

![db](../HTB-pics/dab/09-from-db.png)

When I refresh soon after, I get a different comment indicating that the data came from a cache.

![cache](../HTB-pics/dab/10-from-cache.png)

This is actually fairly typical. If data requires some heavy SQL query to be run every time the user wants that data, it will slow down the site because of all the resource needs. If you just save the output in a cache and then display that data for some period of time until the cache expires, you can save on a lot of resource needs and speed up your site. There's no way to exploit this yet, but maybe the port 8080 site will lead us somewhere. I did the same process with BurpSuite here to intercept a request.

![burp2](../HTB-pics/dab/11-access-denied.png)

Again, the same process with `wfuzz` was used to fuzz the cookie.

![fuzz2](../HTB-pics/dab/12-fuzz-password.png)

A value of `secret` returns a different page. Plugging this cookie into my browser works like a charm.

![cookie-works](../HTB-pics/dab/14-tcp-test.png)



## Initial Foothold

### TCP Socket Test Form

The website is some sort of TCP form to send data. There's no IP field, so I assume it sends to `127.0.0.1`. I did some testing to see what exactly this form was doing. With a port I know is up:

![ssh](../HTB-pics/dab/15-tcp-test-2.png)

With a port I know is down, I get an expected error:

![bad-port](../HTB-pics/dab/16-tcp-test-3.png)

![server-error](../HTB-pics/dab/17-server-error.png)

When I try to do a basic HTTP request, I get an unusual page saying "Hackling Attempt Detected." There must be some sort of filtering for special characters.

![port-80](../HTB-pics/dab/18-tcp-test-4.png)

![hack](../HTB-pics/dab/19-hack-detected.png)

I decide to use `wfuzz` again to see what characters aren't allowed.

![bad-chars](../HTB-pics/dab/20-test-bad-chars.png)

It's basically all of them. I also used the `range` option to test what ports returned data.

![ports](../HTB-pics/dab/21-fuzz-ports.png)

### Memcache

11211 didn't appear on `nmap`. After some research, I discovered it's for a service called `memcache`. This is the caching service I alluded to earlier when talking about the port 80 site. The tools has all sorts of commands you can run to get information about the data in the cache. I intercepted a request in BurpSuite Repeater to enumerate the service more easily. First I ran `stats slabs` to list the available cached data sets.

![stats](../HTB-pics/dab/23-slabs.png)

There are two slabs available: `16` and `26`. I can use `stats cachedump` to see what's contained in these slabs. Slab 26 has user data.

![dump](../HTB-pics/dab/24-cache-dump.png)

If I send the login request again, which repopulates the cache,  and run `get users`, I get users and their hashes.

![users](../HTB-pics/dab/25-users.png)

I need to get this data in a more useful form. First, I copied and pasted it into BurpSuite Decoder. Then I clicked "decode as HTTP" and "Smart Decode".

![decrypt](../HTB-pics/dab/26-decrypt.png)

It's still a garbled mess, but if I copy it and paste into a `nano` window, I get a nice `JSON` object with usernames as keys and MD5 hashes as values.

![json](../HTB-pics/dab/27-json.png)

I can now use `jq` to query this data. I used `jq` to separate the data into a list of users and a list of hashes. 

![query](../HTB-pics/dab/28-query.png)

### User Enumeration -> Shell as Genevieve

The list of users is super long, so I used the Metasploit SSH user enumeration script to narrow down which users were important. I used the following options:

![ssh-enum](../HTB-pics/dab/29-ssh-enum.png)

Then, I ran the script with `set VERBOSE false` to clean the output up a bit. There was only one valid SSH username in the list: `genevieve`.

![user](../HTB-pics/dab/30-genevieve.png)

I then used another `jq` command to find which hash corresponded to `genevieve`.

![hash](../HTB-pics/dab/31-hash.png)

Now, I can crack this hash with `hashcat`, since it's MD5, it cracks in like 2 minutes.

![crack](../HTB-pics/dab/32-crack.png)

I can log in to `ssh` with `genevieve:Princess1` and obtain the user flag.

![user-flag](../HTB-pics/dab/33-user-flag.png)



## Privilege Escalation

### LinPEAS Enumeration

After I get a shell on a box, the first thing I usually do is run LinPEAS, this is just a way to save time as I *could* run these tests manually, but I have a job and school to do, so instead I use LinPEAS. I set up a Python server to my `tools` directory.

![python](../HTB-pics/dab/34-python-server.png)

Then, I make a working directory to ensure I have permissions.

![setup](../HTB-pics/dab/35-setup.png)

I used `curl` to download the script to the box, set permissions, and run the script.

![linpeas](../HTB-pics/dab/36-linpeas.png)

The first thing I notice when running LinPEAS (other than the PolKit vulnerability because this box is old) is that there are several unknown SUID binaries and that `ldconfig` has the SUID bit set.

![suid](../HTB-pics/dab/38-suid.png)

### Library Hijacking

I used `scp` to download the `myexec` binary to inspect what it was doing.

![myexec](../HTB-pics/dab/39-myexec.png)

I used `r2` to help me decompile the program and help me figure out what it's doing. The `aaa` option sets all functions and `afl` stands for "all functions list".

![decomp](../HTB-pics/dab/40-decompile.png)

The main function interested me so I used `pdf`, "print decompiled function", to view the Assembly.

![decomp2](../HTB-pics/dab/41-decompile-2.png)

The password works for the binary, but the login function was just a stub. I then used `ldd` to see what shared objects were needed for `myexec`.

![libseclogin](../HTB-pics/dab/43-ldd.png)

I used the same process of `scp` and `r2` to inspect this file. All the binary did was print the stub message to the console.

![libsec](../HTB-pics/dab/44-libseclogin.png)

![pdf-libsec](../HTB-pics/dab/45-pdf-seclogin.png)

Since `ldconfig` has the SUID bit set, I can check the configuration files. In the configuration directory, there is an unusual `test.conf`.  

![conf](../HTB-pics/dab/46-ldd-2.png)

It specifies that `ldconfig` looks for libraries in `/tmp` before looking in other directories. I have write access to `/tmp`, so I wrote this simple C code in that directory names `libseclogin.c`.

![c-code](../HTB-pics/dab/47-malicious-suid.png)

Then, I compiled the program locally with `gcc` and moved the compiled program to `/tmp`.. Then, I executed `ldconfig` so that `myexec` uses my malicious program instead of the `libseclogin` binary.

![compile](../HTB-pics/dab/48-compile.png)

If I execute `myexec` now, I can own `root` and get the flag.

![root](../HTB-pics/dab/49-root-flag.png)


## Vulnerability Summary

- Insecure Passwords
	- Several of the passwords used: `Password1` for the port 80 admin area, `secret` for the port 8080 cookie, and `Princess1` for the `genevieve` account were dictionary words present in common wordlists.
- No Account Locking
	- I was able to fuzz both web pages without being locked out after numerous incorrect attempts.
- Authentication Request Caching
	- Memcache was used to store login information to improve the speed of authentication at the cost of decreased security.
- Insecure Password Storage
	- Password hashes were stored in the infamously-insecure MD5 hash format and were easily cracked.
- Improper binary permissions
	- The three binaries had the SUID-bit set, meaning they were executed as root, regardless of who actually executed them. This allowed me to poison the library used in the `myexec` binary and own the system.
