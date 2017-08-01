# quasseldb-php
PHP class to manipulate [Quassel](http://quassel-irc.org) databases

This class is created mainly for performing backlog searches

Created by [balintx](mailto:balintx@balintx.me)

# Basic usage example
## Connecting
With PostgreSQL:
```php
include 'quasseldb.php';
$qdb = new QuasselDB();
$success = $qdb->Connect(['localhost', 'username', 'password', 'quassel']);
```
With SQLite:
```php
include 'quasseldb.php';
$qdb = new QuasselDB();
$success = $qdb->Connect(['/home/nasus/.config/quassel-irc.org/quassel-storage.sqlite'], 'sqlite');
```

### Verifying the connection:
```php
if ($success !== true)
{
    error_log("Could not connect to the database: $success");
    die("There was a problem connecting to the Quassel database, please check your webserver's logs for more information.");
}
```

## Authenticating
You must authenticate for an user before touching the backlog.

After authenticating, you will be able to perform backlog actions visible to the authenticated user.

### Authenticating with username and password combination
```php
$success = $qdb->Authenticate('myquasselusername', 'myquasselpassword');
```
### Authenticating with userid and hash combination
```php
$success = $qdb->Authenticate_WithHash(2, 'my_password_hash'); // my userid is 2
```
### Authenticating with the userid
```php
$qdb->user_id = 2;
$success = $qdb->Get_Username($qdb->user_id);
```
> This option may be removed in the future

## Retrieving user's visible buffers (networks and channels/privmsgs)
```php
$my_buffers = $qdb->Get_Buffers();
foreach ($my_buffers as $networkid => $buffers)
{
  echo $qdb->Get_Network([$networkid])[$networkid]." ($networkid)".PHP_EOL;
  foreach ($buffers as list($buffer_id, $buffer_name))
  {
     echo "$buffer_name ($buffer_id) ";
  }
  echo PHP_EOL;
}
```
## Searching in backlog
### Search by message
> Search for the string "was me" in buffers 13, 14, 67 among PRIVMSG and NOTICE texts and getting the last 15 results:
```php
$results = $qdb->Search(
    [QuasselDB_Constants::Search_String => 'was me'],
    [13, 14, 67],
    15,
    [QuasselDB_Constants::BacklogEntry_Type_Plain, QuasselDB_Constants::BacklogEntry_Type_Notice]
);
```
### Search between dates
> Like the previous example, but we restrict the search between 2017-02-01 and 2017-03-01
```php
$date = new DateTime("2017-02-01");
$dates[] = $date->getTimestamp();
$date = new DateTime("2017-03-01");
$dates[] = $date->getTimestamp();

$results = $qdb->Search(
    [
        QuasselDB_Constants::Search_String => 'was me',
        QuasselDB_Constants::Search_Date => $dates
    ],
    [13, 14, 67],
    15,
    [QuasselDB_Constants::BacklogEntry_Type_Plain, QuasselDB_Constants::BacklogEntry_Type_Notice]
);
```
### Search by nick
> We search the last 150 backlog entries related to _paul_ on buffer 1324
```php
$results = $qdb->Search(
    [QuasselDB_Constants::Search_Sender => 'paul'],
    [1324],
    150
);
var_dump($results);
```
Example output:
```
array(4) {
  [0]=>
  array(7) {
    ["messageid"]=>
    int(22271557)
    ["time"]=>
    string(23) "2017-07-30 14:48:50.494"
    ["bufferid"]=>
    int(1324)
    ["type"]=>
    int(32)
    ["flags"]=>
    int(0)
    ["senderid"]=>
    int(1160503)
    ["message"]=>
    string(5) "#test"
  }
  [1]=>
  array(7) {
    ["messageid"]=>
    int(22271560)
    ["time"]=>
    string(23) "2017-07-30 14:48:54.933"
    ["bufferid"]=>
    int(1324)
    ["type"]=>
    int(1)
    ["flags"]=>
    int(1)
    ["senderid"]=>
    int(17)
    ["message"]=>
    string(10) "hello guys"
  }
  [2]=>
  array(7) {
    ["messageid"]=>
    int(22271561)
    ["time"]=>
    string(23) "2017-07-30 14:49:06.318"
    ["bufferid"]=>
    int(1324)
    ["type"]=>
    int(1)
    ["flags"]=>
    int(1)
    ["senderid"]=>
    int(17)
    ["message"]=>
    string(27) "oh, the silence is big here"
  }
  [3]=>
  array(7) {
    ["messageid"]=>
    int(22271562)
    ["time"]=>
    string(23) "2017-07-30 14:49:11.354"
    ["bufferid"]=>
    int(1324)
    ["type"]=>
    int(64)
    ["flags"]=>
    int(0)
    ["senderid"]=>
    int(1160503)
    ["message"]=>
    string(52) "http://quassel-irc.org - Chat comfortably. Anywhere."
  }
}
```
### Searching messages near a given messageid
The code below will retrieve the backlog entry on the given buffer (1324) with the given messageid (22271562), plus the previous and next 15 entries
```php
$entries = $qdb->Get_MessagesNearID(22271562, 1324, 15);
```
If you need only the previous 15 entries:
```php
$entries = $qdb->Get_MessagesNearID(22271562, 1324, 15, QuasselDB_Constants::Direction_Previous);
```
If you need only the next 15 entries:
```php
$entries = $qdb->Get_MessagesNearID(22271562, 1324, 15, QuasselDB_Constants::Direction_Next);
```
If you need the next and previous 15 entries:
```php
$entries = $qdb->Get_MessagesNearID(22271562, 1324, 15, QuasselDB_Constants::Direction_Previous | QuasselDB_Constants::Direction_Next);
```
If you need the next 15 entries plus the original message (22271562):
```php
$entries = $qdb->Get_MessagesNearID(22271562, 1324, 15, QuasselDB_Constants::Direction_Previous | QuasselDB_Constants::Original_Message);
```
If you need the message with the given messageid:
```php
$entries = $qdb->Get_MessagesNearID(22271562, 1324);
```
or
```php
// same as above
$entries = $qdb->Get_MessagesNearID(22271562, 1324, 0, QuasselDB_Constants::Original_Message);
```


## Changing authenticated user's password
```html
Old password: <input type="password" name="password"><br>
New password: <input type="password" name="new_password">
<input type="password" name="new_password2" placeholder="(new password again)"><br>
<input type="submit" value="Change password">
```
```php
if ($_POST['new_password'] != $_POST['new_password2']) die('Passwords do not match');
if (!$qdb->Change_Password($_POST['new_password'], $_POST['password'])) die('Failed to change password');
echo 'Password changed successfully!<br>';
```
> Change_Password can be called without the second parameter, although it is not recommended to do so.

## Other Core user manipulation
#### The following functions require no authentication, so be careful who you allow to execute these functions.
### Creating a core user
Creating user mspaint with password 'lolcat':
```php
$success = $qdb->Create_User('mspaint', 'lolcat');
```

### "Removing" a core user
This function does not delete any entries, just prevents login and auto-reconnecting to servers.
It is recommended to perform a core restart just after using QuasselDB::Deactivate_User()
```php
$qdb->Deactivate_User('mspaint');
```

### Make a "removed" core user able to login again
```php
$qdb->Activate_User('mspaint');
```
