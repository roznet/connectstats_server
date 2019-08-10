#!/usr/bin/env python

import pprint
import requests
import urllib
import time
import string
import random
import hashlib
import hmac
import re
import mysql.connector
import base64
import argparse

class ConnectStatsRequest:

    def __init__(self):
        regexp = re.compile("'([a-zA-Z_]+)' +=> +'([-0-9a-zA-Z_!]+)'," )
        config = dict()
        with open( '../api/config.php' ) as cf:
            for line in cf:
                m = regexp.search( line )
                if m:
                    config[ m.group(1) ] = m.group(2)

        self.consumerKey = config['consumerKey']
        self.consumerSecret = config['consumerSecret']

        self.db = mysql.connector.connect(
            host=config['db_host'],
            user=config['db_username'],
            passwd=config['db_password'],
            database=config['database']
            )
        
    def setup_token_id(self,token_id ):
        cursor = self.db.cursor()
        cursor.execute( 'SELECT userAccessToken,userAccessTokenSecret FROM tokens WHERE token_id = %s', (token_id, ) )

        row = cursor.fetchone()
        
        self.userAccessToken = row[0]
        self.userAccessTokenSecret = row[1]
        
    def id_generator(self, size=6, chars=string.ascii_uppercase + string.digits):
        return ''.join(random.choice(chars) for _ in range(size))
    
    def authentification_header(self, accessUrl ):

        method = "GET"

        parsed = urllib.parse.urlparse( accessUrl )
        get_params = dict( urllib.parse.parse_qsl( parsed.query ) )
        url_base = parsed.scheme + "://" + parsed.netloc + parsed.path
        
        nonce = self.id_generator(16)

        now = str(int(time.time()))

        oauthmethod = "HMAC-SHA1"
        oauthver = "1.0"


        oauth_params ={"oauth_consumer_key":self.consumerKey,
                       "oauth_token" :self.userAccessToken,
                       "oauth_nonce":nonce,
                       "oauth_timestamp":now,
                       "oauth_signature_method":oauthmethod,
                       "oauth_version":oauthver}

        all_params = dict(oauth_params)
        all_params.update( get_params )

        all_params_order = sorted(all_params.keys())

        params = '&'.join( [ '{}={}'.format( x, urllib.parse.quote(all_params[x]) )  for x in all_params_order ] )
        
        base = '&'.join( [ method, urllib.parse.quote(url_base, safe=''), urllib.parse.quote( params ) ] )

        key = '&'.join( [ urllib.parse.quote(self.consumerSecret), urllib.parse.quote(self.userAccessTokenSecret) ] )

        digest = hmac.new( key.encode('utf-8'), base.encode('utf-8'), hashlib.sha1 ).digest()
        signature = base64.b64encode( digest )

        oauth_params['oauth_signature'] = signature

        oauth_params_order = sorted( oauth_params.keys() )

        header_params = ', '.join( [ '{}="{}"'.format( x, urllib.parse.quote( oauth_params[x] ) ) for x in oauth_params_order] )
        
        header = 'OAuth {}'.format( header_params )

        headers = {'Authorization': header}
        
        request = urllib.request.Request(accessUrl, headers=headers )
        
        response = urllib.request.urlopen( request )
        
        return( response.read() )




if __name__ == "__main__":
    
    parser = argparse.ArgumentParser( description='Query ConnectStats API', formatter_class=argparse.RawTextHelpFormatter )
    parser.add_argument( 'url' )
    parser.add_argument( '-t', '--token', help='Token id for the access Token Secret', default = 1 )
    args = parser.parse_args()
    
    a = ConnectStatsRequest()

    a.setup_token_id( args.token )

    print( a.authentification_header( args.url ) )

