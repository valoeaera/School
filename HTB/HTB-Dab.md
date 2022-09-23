Name: Ben Roudebush
Lab: HTB - Dab
IP Address: 10.129.227.201
Date: 29 August 2022

## Table of Contents

| Page Number | Section |
| - | - |
| 2 | High-Level Summary |
| 3 | Service Enumeration |
| 8 | Initial Foothold |
| 11 | Privilege Escalation |
| 14 | Recommendations |

## High-Level Summary

Dab is a Linux-based web server, with ports 21, 22, 80, and 8080 initially appearing to be open. Port 21 can quickly be dismissed, as the only file available for sharing is a simple JPG. Ports 80 and 8080 are on the same host (there aren’t any VM shenanigans going on), but are hosting different webpages. The port 80 web page is a simple HTML login form. Fuzzing this form with the username of ‘admin’ and a short password wordlist reveals the combination of ‘admin:Password1’. Once logged in, the page appears mostly static, with the only interactive element being the ‘Logout’ button. 

The port 8080 web page initially informs the user that the required ‘password’ cookie is not set. This, too, can be fuzzed, revealing a valid value of ‘secret’. Once authenticated, the page contains a simple form for sending TCP data. If the TCP port field is fuzzed, the four ports mentioned earlier return data (i.e. they returned a response code not equal to 500) in addition to a new port: 11211. Port 11211 indicates that memcache is also running on the server. Memcache allows functions like database queries to have their results cached to reduce the load on the web server.

Using the TCP socket form, slabs (the name for sets of cached data) can be viewed. If a login request is sent immediately before fetching slab 26 (a set of user data), a list of users and MD5 hashes can be obtained. Next, the list of users can be run through a Metasploit module for enumerating SSH users. One user from the list, `geneviene`, returns as valid for SSH. Geneviene’s hash can be cracked with Hashcat rather trivially to find the password `Princess1`.

Once a shell has been obtained through SSH, initial enumeration reveals a few binaries with the SUID binary set: `try_harder`, `ldconfig`, and `myexec`. `try_harder` appears to be an attempt to mislead the attacker, and `myexec` seems to be benign on first blush. Upon further inspection, the `myexec` binary utilizes a shared library called `libseclogin`. Because `geneviene` has SUID access to `ldconfig`, which is used to configure libraries, a malicious version of the `libseclogin` binary can be created and linked to the `myexec` binary. Now, because `myexec` has the SUID bit set, executing `myexec` while it is linked to this malicious `libseclogin` will spawn a shell as root.

## Service Enumeration

As always, initial enumeration began with a port scan conducted by Nmap. Nmap is an enumeration tool which returns all the open ports on a target host and some information about the services listening on those ports.

![nmap](C:\Users\valoe\Documents\HTB\dab\01-nmap.png)

Four services were returned by Nmap: FTP (21), SSH (22), HTTP (80), and another HTTP (8080). The web pages on 80 and 8080 appeared to both be running on the host itself as the nginx version returned was identical; however, they were hosting different pages. First, FTP was investigated, as Nmap was able to list the contents available for share. This indicated that anonymous login was enabled, which would allow files to be downloaded for further investigation. The JPG file indicated in the Nmap results was downloaded after authenticating to the FTP server with anonymous credentials.

![ftp](C:\Users\valoe\Documents\HTB\dab\02-ftp.png)

The file turned out to be a red herring of sorts, as no strings or otherwise hidden data was found in the file and the image was simply a low-resolution image of the [dab](https://www.merriam-webster.com/dictionary/dab) dance move.

![dab](C:\Users\valoe\Documents\HTB\dab\03-dab.png)

The service that was investigated was the web page available on port 80. Upon visiting the page, a very rudimentary HTML web login page appeared.

![login](C:\Users\valoe\Documents\HTB\dab\04-login.png)

Typical easy-win credential pairs were attempted, but proved unsuccessful. Before moving to more noisy investigation methods, the 8080 web page was also inspected. This time, an error message was returned, indicating that a required ‘password’ cookie was not set.

![cookie](C:\Users\valoe\Documents\HTB\dab\05-cookie.png)

