<?php
declare(strict_types=1);

// Called inside the admission transaction. No native accounts are created or updated here.
function mcpLinkOAuthUser(string $identity, string $email): User {
  // ponytail: global admission lock; use per-identity/mailbox locks if login throughput grows.
  if (!Sql::query("SELECT pg_advisory_xact_lock(hashtext('mcp-oauth-admission'))")) throw new RuntimeException('OAuth identity lock failed');
  if (!Sql::query('CREATE TABLE IF NOT EXISTS mcpoauthidentity (id serial PRIMARY KEY, identity varchar(70) NOT NULL UNIQUE, iduser integer NOT NULL UNIQUE REFERENCES resource(id))')) throw new RuntimeException('OAuth identity storage unavailable');
  $result=Sql::query('SELECT iduser FROM mcpoauthidentity WHERE identity='.Sql::str($identity));
  $link=Sql::fetchLine($result);
  if ($link) {
    $user=new User((int)$link['iduser']);
  } else {
    $users=(new User())->getSqlElementsFromCriteria(null,false,'lower(trim(email))='.Sql::str($email));
    if (count($users)!==1) throw new RuntimeException('oauth_user_unavailable');
    $user=reset($users);
    if (!$user->id || $user->idle || $user->locked) throw new RuntimeException('oauth_user_unavailable');
    $existing=Sql::query('SELECT identity FROM mcpoauthidentity WHERE iduser='.Sql::fmtId((int)$user->id));
    if (Sql::fetchLine($existing)) throw new RuntimeException('oauth_user_unavailable');
    if (!Sql::query('INSERT INTO mcpoauthidentity (identity,iduser) VALUES ('.Sql::str($identity).','.Sql::fmtId((int)$user->id).')')) throw new RuntimeException('OAuth identity link failed');
  }
  if (!$user->id || $user->idle || $user->locked) throw new RuntimeException('oauth_user_unavailable');
  return $user;
}

function mcpOAuthLinkedUser(string $identity): User {
  $result=Sql::query('SELECT iduser FROM mcpoauthidentity WHERE identity='.Sql::str($identity));
  $link=Sql::fetchLine($result);
  $user=$link ? new User((int)$link['iduser']) : new User();
  if (!$user->id || $user->idle || $user->locked) throw new RuntimeException('oauth_user_unavailable');
  return $user;
}
