Name: Ben Roudebush

Lab: HTB - Control

IP Address: 10.129.229.113

Date: 24 October 2022

## High Level Summary

Control is a seemingly simple Windows web server, with the slightly unusual MySQL where one would expect MSSQL. The exploitation starts with discovering that I can forge an HTTP header and access the admin section of the site without credentials. Then, I probe a SQL injection vulnerability to obtain both file write and some user hashes. Using the file write, I can get a webshell on the box and use that to get a reverse shell back to my machine. One of the hashes I found from SQL can now be used to pivot to the `hector` account. This is where the box gets difficult. `Hector` is a very high-privileged user, and the path to `system` involves editing the registry keys associated with certain services. Doing this, I can edit the path of one of the services to point to `nc64.exe`, restart the service, and because services run as `system`, I own the box.

## Service Enumeration

### Nmap

![](../HTB-pics/control/01.png)

Nmap shows three ports open:

- HTTP on port 80: running `IIS`, meaning this is a Windows box
- MSRPC on port 135: further cementing that this is Windows
- MySQL on port 3306: normally, you would expect MSSQL on 1433, given this is a Windows box. But this isn't *unheard-of*, just strange.

### Web Enumeration

![](../HTB-pics/control/02.png)

The web page seems to be a very simple page for a tech company. Clicking on the `About` section also doesn't give me much. If I click on `Admin` instead of getting a login page like you'd expect, I instead get this error:

![](../HTB-pics/control/03.png)

It's telling me I need to go through a proxy to access the page. On the source of the home page, there's a hint for what I need to do.

![](../HTB-pics/control/04.png)

The SSL certificates are at `192.168.4.28`. I don't know what HTTP Header the page expects, but with `wfuzz` and this hint as to an IP address of the proxy, I can find it out.

![](../HTB-pics/control/05.png)

As with all `wfuzz` commands in my guides, I've omitted the initial run of the command without the `--hh` flag. Normally, you'd run `wfuzz` once to see what the typical response looks like, and then hide that with the appropriate `--hx` flag. Anyway, hiding all 89 character responses, I can see that the server wants `X-Forwarded-For` set to the IP from above. I can use a Firefox extension to edit my HTTP headers for this URL.

![](../HTB-pics/control/06.png)

![](../HTB-pics/control/07.png)

When I reload the admin page, I get a product catalog instead of the error message.

## Initial Foothold

### SQL Injection

I want to play around with all of the different entry fields on this page, both because I saw MySQL open earlier, and because there's data being returned from a query. Both of these indicate that the page might be SQL-injectable. If I Pull up the request from searching for a product in Burp, I can mess with the query that's being run.

![](../HTB-pics/control/08.png)

If I put a `'` at the end, I get an error. That means I can inject into this query.

![](../HTB-pics/control/09.png)

If I try to pull in other data (here just test data), I get a cardinality error. Regular [vsauce](https://www.youtube.com/c/vsauce1) viewers, or those that can read error messages, will know this means that the set of data returned from the first query and my test query have a different number of columns.

![](../HTB-pics/control/10.png)

I can pretty easily figure out how many columns it wants by counting the number of fields on the page, which turns out to be 6.

![](../HTB-pics/control/11.png)

Using this query, I can discover my current username: `manager`.

![](../HTB-pics/control/14.png)

Now, I can dump the database names. I get:

- `information_schema`: the database that contains all of the information about this MySQL instance: database names, table names, etc.
- `mysql`: the database that contains user data
- `warehouse`: the only non-standard database here; probably where the product info is coming from

![](../HTB-pics/control/12.png)

I want user info, so I'll grab the IDs, usernames, and password hashes from `mysql`. I get a bunch of them.

![](../HTB-pics/control/13.png)

### Hashes

I grabbed the raw HTML data and copied it to a file.

![](../HTB-pics/control/15.png)

Then, I can use a combination of `html2text` and `awk` to clean out all the HTML tags.

![](../HTB-pics/control/16.png)

Finally, I can use `awk` again to get just the hash. Then (not pictured here) I used `sed` to remove the `*` from the beginning. I started these cracking, but in the meantime I can do some more stuff with SQL.

![](../HTB-pics/control/17.png)

### File Write

If I scroll through the privileges present on the box, I can find that `manager` has file permissions.

![](../HTB-pics/control/18.png)

I can use `SELECT x INTO OUTFILE` to write the contents of a variable into a file. I put a webshell into the first of those and I get this error.

![](../HTB-pics/control/19.png)

But, if I curl against the webshell, I get a response.

![](../HTB-pics/control/20.png)

To turn this into a real shell, I'll host `nc64.exe`  on a python web server.

![](../HTB-pics/control/21.png)

I'll also start a `nc` listener for `nc64.exe` to hit against in a new tab.

![](../HTB-pics/control/22.png)

I use `PowerShell` to download `nc64.exe` to `C:\Windows\Temp`, which I probably have write access to.

![](../HTB-pics/control/23.png)

Then, I execute `nc64.exe` against the listener. It hangs, but that's a good thing. 

![](../HTB-pics/control/24.png)

I have a low-level shell on my listener.

![](../HTB-pics/control/25.png)

### PowerShell Credential Object, Pivot to `hector`

I'll also grab WinPEAS to the box. This might not run because of Windows Defender, but it is always a good bet to try to save time.

![](../HTB-pics/control/26.png)

Surprisingly, it runs.

![](../HTB-pics/control/27.png)

There's a user `hector`, which was also indicated by MySQL. WinPEAS indicates that his password doesn't expire, so the hash probably still works.

![](../HTB-pics/control/28.png)

If I try to crack these hashes, `hashcat` can't find the hash format. But one of the suggestions it gives is `300` for MySQL, so I'll use that.

![](../HTB-pics/control/31.png)

One of the passwords cracks to `l33tth4x0rhector`. Seems `hector` has a bit of an ego problem.

![](../HTB-pics/control/32.png)

I now need to create a credential object with this password. I'll use `PowerShell` and some environment variables to do so.

![](../HTB-pics/control/33.png)

Then, I convert the `$password` variable to a Secure String.

![](../HTB-pics/control/34.png)

Then, I use both the `$username` and `$SecureStr` to make a credential object. If I use it to Invoke-Command, I'm `hector`.

![](../HTB-pics/control/35.png)

I'll set up another listener in a new tab.

![](../HTB-pics/control/36.png)

I tried to use the same `nc64` binary, but it seems `hector` doesn't have access to `C:\Windows\Temp` so I'll use a new one in `C:\Windows\System32\spool\drivers\color`, another common working directory.

![](../HTB-pics/control/37.png)

And I've got a shell and can grab the user flag.

![](../HTB-pics/control/38.png)

## Privilege Escalation

### Registry Poisoning, Pivot to `system`

I'll grab a new WinPEAS binary for the same reason as `nc64`.

![](../HTB-pics/control/39.png)

WinPEAS lights up like a Christmas tree when I get to the modifiable services section. I can edit the Registry Keys, which means I can pollute the path of a service and get a `system` shell.

![](../HTB-pics/control/40.png)

There's also a `ConsoleHost_history` file that contains console history, like a `bash_history` file on Linux.

![](../HTB-pics/control/41.png)

Essentially, the file is just another hint to go look at HKLM and editing the registry, which WinPEAS already told us.

![](../HTB-pics/control/42.png)

If we go into `HKLM` and poke around, we can see how the date is formatted.

![](../HTB-pics/control/47.png)

So what we need to do is find a service that satisfies four conditions:

1. The service runs as `system`
2. The service is not yet started
3. `hector` has permissions to start the service
4. `hector` has permissions to change the service path

In the below command, we make a list that satisfies the first two conditions. First, we get a list of all services with `Get-ItemProperty`. Then, we match on `LocalSystem` to satisfy the first condition. And matching on `3` satisfies the second condition because that's the value that corresponds to "Not Started".

![](../HTB-pics/control/48.png)

Now, we use the following mess to check if we can start the service and change it's path.  The ACL is not human-readable, so we need to decode it. `RP` doesn't mean either of those things, but the command we're using is not meant for Registry Keys. `AU` just means any authenticated user can perform the action. The characters between the two just say we don't care what other permissions are present.

![](../HTB-pics/control/49.png)

We get a super manageable list of less than 10 services. We'll use `wuauserv`. I set up another listener in another new tab. The first command just sets the path of the service to point to `nc64` instead of what it's supposed to. Then the last command executes "wuauserv" (but actually `nc64`).

![](../HTB-pics/control/50.png)

And I have a shell on the listener and can grab the flag.

![](../HTB-pics/control/51.png)

## Recommendations

- Informative Code Comments
	- Don't put "To-Do" lists in code comments that will be hosted on a web server, especially items that contain IP addresses or other sensitive information.
	- I would recommend this comment be deleted from the source.
- Informative Error Messages
	- The admin portal informs the user how it is authenticating. This in-and-of-itself is not horrible, but you should, as a general rule, not give potential attackers any more information than is strictly necessary.
	- I would recommend changing the `Access Denied...` text that currently appears to just say `Access Denied`.
- Inadequate Authentication
	- Simply authenticating by checking for an HTTP header is not a good idea, as those can be easily spoofed, as demonstrated above.
	- I would recommend checking that the user is logged in instead of just checking an HTTP header.
- SQL Injection
	- The admin product catalog is vulnerable to SQL injection.
	- This can be fixed using "parameterized statements".