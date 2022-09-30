Name: Ben Roudebush

Lab: HTB - Tally

IP Address: 10.129.228.201 // 10.129.228.204

Date: 30 September 2022

## High-Level Summary

Tally is a Windows machine that shows off a few different services. First, a SharePoint instance gives the attacker credentials for FTP. Within the FTP share is a KeePass file which contains credentials for an SMB share. On that SMB share is an executable test file that makes a connection to an MSSQL database as the SA. Those credentials can be used to enable `xp_cmdshell` and get a shell as `Sarah`. From there, the attacker can use meterpreter and RottenPotato to impersonate `NT AUTHORITY/SYSTEM` and own the box.

## Service Enumeration

### Nmap

![nmap](../HTB-pics/tally/01-nmap.png)

Nmap shows several ports up, notably:

- FTP
- HTTP
- SMB
- MSSQL

There are many other ports open, but these are the services of interest, as all of these services are commonly useful from a pentesting perspective.

### SharePoint

The webpage on port 80 is a SharePoint instance. Initially, nothing appears. SharePoint often works better when accessed by hostname, so I added `tally 10.129.228.201`  to `/etc/hosts`. When I visit http://tally, I get nothing different. Looking online, I find [this article](https://resources.bishopfox.com/resources/tools/sharepoint-hacking-diggity/attack-tools/), which points to `_layouts/viewlists.aspx` as a possible path of interest. If I go to http://tally/_layouts/viewlists.aspx, I get two files:

![doc](../HTB-pics/tally/02-sharepoint-doc.png)

![usernames](../HTB-pics/tally/03-usernames.png)

The first file gives me a password and the second file gives me a username. Putting the two together, I get `ftp_user:UTDRSCH53c"$6hys`.

### FTP Enumeration

I can log into FTP with the above credentials and see some folders.

![ftp](../HTB-pics/tally/04-ftp.png)

I can use `wget` to get all of the files available to this user, which takes quite a bit as there are a lot of files.

![get-all](../HTB-pics/tally/05-get-all.png)

In the `User` directory, I look for files and find a KeePass database.

![files](../HTB-pics/tally/06-find-files.png)


## Initial Foothold

### KeePass Database

I now want to crack the master password to unlock this KeePass database, so I use `keepass2john` to format it for cracking.

![keepass2john](../HTB-pics/tally/07-keepass2john.png)

Then I can use `hashcat` with auto-detection to crack the password.

![cracking](../HTB-pics/tally/08-cracking.png)

It only takes a minute or two to crack to `simplementeyo`.

![cracked](../HTB-pics/tally/09-cracked.png)

I can use `kpcli` to inspect the contents of the database now that I have the master password.

![kpcli](../HTB-pics/tally/10-kpcli.png)

There are three credentials in the database, one of which is for SMB.

![creds](../HTB-pics/tally/11-creds.png)

### SMB Enumeration

I used `crackmapexec` to view the available shares on the target. 

![smb](../HTB-pics/tally/12-smb.png)

There are a ton of files on the share, so I mounted the share instead of downloading everything.

![mount](../HTB-pics/tally/13-mount-smb.png)

After some looking around, there's a folder with some .EXE files in it. One of them has a database connection with credentials in plaintext.

![tester](../HTB-pics/tally/14-tester.png)

![strings](../HTB-pics/tally/15-strings.png)

![creds](../HTB-pics/tally/16-more-creds.png)

### MSSQL -> Shell as Sarah

I can use these credentials to log into MSSQL as the SA. I used impacket's `mssqlclient.py`. Since I'm `sa`, I can enable `xp_cmdshell` otherwise known as RCE.

![mssql](../HTB-pics/tally/17-mssqlclient.png)

I set up a listener on port `42069` with `nc`.

![listener](../HTB-pics/tally/18-listener.png)

I then used [this site](https://www.revshells.com/) to generate a base64-encoded PowerShell reverse shell. I can then use `xp_cmdshell` to run it and get a shell as Sarah.

![payload](../HTB-pics/tally/19-encoded%20payload.png)

As Sarah, I can read the user flag.

![flag](../HTB-pics/tally/20-user-flag.png)


## Privilege Escalation

### SeImpersonate, Sarah -> NT AUTHORITY/SYSTEM

If I execute `whoami /priv`, Sarah has `SeImpersonatePrivilege` enabled, which means I can use [RottenPotato](https://github.com/foxglovesec/RottenPotato) to impersonate `NT AUTHORITY/SYSTEM`.  I used IWR to get Rotten Potato on the target in a working directory.

![rp](../HTB-pics/tally/21-get-rotten-potato.png)

Then, I used `msfvenom` to create a Windows payload. This step isn't strictly necessary, but I want to use `msfconsole` 's `impersonate_token` because it's way easier than trying to do it fast enough to beat the token expiring.

![payload](../HTB-pics/tally/22-msfvenom.png)

I then hosted the file on my Python server.

![python-server](../HTB-pics/tally/23-host-file.png)

And I can use IWR again to download it.

![download](../HTB-pics/tally/24-get-payload.png)

Then, I used `msfvenom` to start a handler to listen for my payload. I configured it with the same options I used in `msfvenom`.

![handler](../HTB-pics/tally/25-msf-handler.png)

Finally, I can run the payload on the target.

![start](../HTB-pics/tally/26-start-payload.png)

I get a meterpreter shell on my handler. Then I do a bunch of commands to get a token. I execute RottenPotato to steal the token, then impersonate that token, and re-enter my shell. When I'm back in, I have Administrator privileges and can read the root flag.

![flag](../HTB-pics/tally/27-root-flag.png)


## Vulnerability Summary

- Unsafe credential storage
	- FTP credentials were stored in plaintext on a publicly-accessible SharePoint drive.
	- MSSQL administrator credentials were hard-coded in the `tester.exe` binary.
- Simple KeePass master key
	- The password used as the master key for KeePass was easily crackable in under 2 minutes.
- Incorrect privilege settings
	- The Sarah user had dangerous permissions set that allowed impersonation of administrator.