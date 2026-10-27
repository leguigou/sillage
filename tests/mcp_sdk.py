"""Validate the server with the official MCP Python SDK (v1.30.0)."""
import asyncio,os,json
from mcp import ClientSession
from mcp.client.streamable_http import streamablehttp_client
from jsonschema import Draft202012Validator

async def main():
    headers={'Authorization':'Bearer '+os.environ['SILLAGE_MCP_TEST_KEY']}
    url=os.environ.get('SILLAGE_TEST_URL','http://127.0.0.1:8089')+'/mcp'
    async with streamablehttp_client(url,headers=headers) as (read,write,_):
        async with ClientSession(read,write) as session:
            initialized=await session.initialize()
            assert initialized.serverInfo.name=='sillage'
            tools=await session.list_tools()
            for tool in tools.tools: Draft202012Validator.check_schema(tool.inputSchema)
            result=await session.call_tool('workspace_info',{})
            assert not result.isError and json.loads(result.content[0].text)['company']
            print('Official MCP SDK: initialize, discovery, JSON Schema 2020-12 and call verified.')
asyncio.run(main())
