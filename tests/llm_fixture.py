"""Synthetic local LLM server for isolated tests; never uses real provider credentials."""
import json
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

class LlmFixtureHandler(BaseHTTPRequestHandler):
    calls=[]
    def log_message(self,*args): pass
    def reply(self,data,status=200):
        payload=json.dumps(data).encode()
        self.send_response(status);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(payload)));self.end_headers();self.wfile.write(payload)
    def do_GET(self):
        if self.headers.get('Authorization')=='Bearer bad': return self.reply({'error':'rejected bad'},401)
        if self.path=='/v1/models': return self.reply({'data':[{'id':'sillage-text-test'},{'id':'other-test-model'}]})
        if self.path=='/ollama/api/tags': return self.reply({'models':[{'name':'local-text-test'}]})
        if self.path=='/empty/v1/models': return self.reply({'data':[]})
        if self.path=='/redirect/v1/models': self.send_response(302);self.send_header('Location','http://169.254.169.254/');self.end_headers();return
        if self.path=='/bad-json/v1/models':
            self.send_response(200);self.end_headers();self.wfile.write(b'not-json synthetic-secret');return
        return self.reply({'error':'wrong URL'},404)
    def do_POST(self):
        data=json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        self.calls.append({'path':self.path,'body':data,'authorization':self.headers.get('Authorization')})
        if self.path=='/v1/chat/completions': return self.reply({'choices':[{'message':{'content':'Proposition de test : clarifier le besoin et les critères de validation.'},'finish_reason':'stop'}]})
        if self.path=='/ollama/api/chat': return self.reply({'message':{'content':'Réponse locale de test.'},'done':True})
        return self.reply({'error':'unknown generation endpoint'},404)


if __name__ == '__main__':
    import argparse
    parser=argparse.ArgumentParser();parser.add_argument('--port',type=int,default=0)
    server=ThreadingHTTPServer(('127.0.0.1',parser.parse_args().port),LlmFixtureHandler)
    print('Synthetic LLM fixture port',server.server_port,flush=True)
    try: server.serve_forever()
    except KeyboardInterrupt: pass
    finally: server.server_close()
