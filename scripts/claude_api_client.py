#!/home/piscataway/venv/bin/python3
"""
Minimal Anthropic API client — replaces the claude CLI for transcript summarization.

Accepts the same CLI flags generate_meeting_summary.php passes to the claude binary,
reads the prompt from stdin, and emits the same JSON envelope the PHP script expects.

Exit codes: 0=success  1=error  2=rate-limited
"""
import sys
import json
import argparse
import anthropic


def main():
    parser = argparse.ArgumentParser(add_help=False)
    parser.add_argument('--print', action='store_true')
    parser.add_argument('--output-format', default='text')
    parser.add_argument('--model', required=True)
    parser.add_argument('--json-schema', dest='json_schema')
    parser.add_argument('--no-session-persistence', action='store_true')
    args = parser.parse_args()

    prompt = sys.stdin.read()
    if not prompt.strip():
        print(json.dumps({'is_error': True, 'api_error_status': 0, 'result': 'No prompt on stdin'}))
        sys.exit(1)

    schema = None
    if args.json_schema:
        try:
            schema = json.loads(args.json_schema)
        except json.JSONDecodeError as e:
            print(json.dumps({'is_error': True, 'api_error_status': 0, 'result': f'Invalid JSON schema: {e}'}))
            sys.exit(1)

    client = anthropic.Anthropic()

    create_kwargs = {
        'model': args.model,
        'max_tokens': 8192,
        'messages': [{'role': 'user', 'content': prompt}],
    }
    if schema and args.output_format == 'json':
        create_kwargs['output_config'] = {
            'format': {'type': 'json_schema', 'schema': schema}
        }

    try:
        response = client.messages.create(**create_kwargs)
    except anthropic.RateLimitError as e:
        print(json.dumps({'is_error': True, 'api_error_status': 429, 'result': str(e)}))
        sys.exit(2)
    except anthropic.APIStatusError as e:
        print(json.dumps({'is_error': True, 'api_error_status': e.status_code, 'result': e.message}))
        sys.exit(1)
    except Exception as e:
        print(json.dumps({'is_error': True, 'api_error_status': 0, 'result': str(e)}))
        sys.exit(1)

    text = next((b.text for b in response.content if b.type == 'text'), '')

    if args.output_format == 'json':
        try:
            structured = json.loads(text)
        except json.JSONDecodeError as e:
            print(json.dumps({'is_error': True, 'api_error_status': 0,
                              'result': f'Non-JSON response: {e}\n{text[:500]}'}))
            sys.exit(1)
        print(json.dumps({'is_error': False, 'structured_output': structured, 'result': text}))
    else:
        print(text)


if __name__ == '__main__':
    main()
