package output

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"strconv"

	"github.com/toon-format/toon-go"
)

// decode reads one JSON value and keeps the key order of every object.
// Objects become toon.Object, lists []any, and numbers json.Number.
func decode(data []byte) (any, error) {
	dec := json.NewDecoder(bytes.NewReader(data))
	dec.UseNumber()
	v, err := readValue(dec)
	if err != nil {
		return nil, err
	}
	if _, err := dec.Token(); err != io.EOF {
		return nil, fmt.Errorf("unexpected data after the JSON value")
	}
	return v, nil
}

func readValue(dec *json.Decoder) (any, error) {
	tok, err := dec.Token()
	if err != nil {
		return nil, err
	}
	d, ok := tok.(json.Delim)
	if !ok {
		return tok, nil
	}
	if d == '{' {
		var fields []toon.Field
		for dec.More() {
			kt, err := dec.Token()
			if err != nil {
				return nil, err
			}
			v, err := readValue(dec)
			if err != nil {
				return nil, err
			}
			fields = append(fields, toon.Field{Key: kt.(string), Value: v})
		}
		if _, err := dec.Token(); err != nil {
			return nil, err
		}
		return toon.NewObject(fields...), nil
	}
	items := []any{}
	for dec.More() {
		v, err := readValue(dec)
		if err != nil {
			return nil, err
		}
		items = append(items, v)
	}
	if _, err := dec.Token(); err != nil {
		return nil, err
	}
	return items, nil
}

// compact writes a decoded value as compact JSON with the key order kept.
func compact(v any) string {
	var b bytes.Buffer
	writeCompact(&b, v)
	return b.String()
}

func writeCompact(b *bytes.Buffer, v any) {
	switch t := v.(type) {
	case toon.Object:
		b.WriteByte('{')
		for i, f := range t.Fields {
			if i > 0 {
				b.WriteByte(',')
			}
			writeScalar(b, f.Key)
			b.WriteByte(':')
			writeCompact(b, f.Value)
		}
		b.WriteByte('}')
	case []any:
		b.WriteByte('[')
		for i, e := range t {
			if i > 0 {
				b.WriteByte(',')
			}
			writeCompact(b, e)
		}
		b.WriteByte(']')
	default:
		writeScalar(b, v)
	}
}

func writeScalar(b *bytes.Buffer, v any) {
	enc := json.NewEncoder(b)
	enc.SetEscapeHTML(false)
	_ = enc.Encode(v)
	b.Truncate(b.Len() - 1)
}

// toToon turns json.Number into the number types the TOON encoder reads.
// A number above 2^53 loses precision here, as the design records.
func toToon(v any) any {
	switch t := v.(type) {
	case toon.Object:
		fields := make([]toon.Field, len(t.Fields))
		for i, f := range t.Fields {
			fields[i] = toon.Field{Key: f.Key, Value: toToon(f.Value)}
		}
		return toon.NewObject(fields...)
	case []any:
		out := make([]any, len(t))
		for i, e := range t {
			out[i] = toToon(e)
		}
		return out
	case json.Number:
		if n, err := strconv.ParseInt(t.String(), 10, 64); err == nil {
			return n
		}
		f, _ := t.Float64()
		return f
	}
	return v
}

func asObject(v any) (toon.Object, bool) {
	o, ok := v.(toon.Object)
	return o, ok
}
