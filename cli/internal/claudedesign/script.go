package claudedesign

import (
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/dop251/goja"
)

// scriptTimeout stops a canvas script that loops.
var scriptTimeout = 2 * time.Second

// prelude is the part of the Design runtime a canvas script needs at build
// time. Timers do nothing, and no mount hook runs. The window stub is no
// browser. It answers a design system namespace, such as
// window.LoupeDesignSystem_686f56, with a namespace whose members are
// component names, and React.createElement turns a call on one of them into a
// description that the page draws in the viewer. Any other window member is
// undefined.
const prelude = `
class DCLogic {
  constructor(props) { this.props = props || {}; this.state = {}; }
  setState(patch) {
    const next = typeof patch === 'function' ? patch(this.state, this.props) : patch;
    this.state = Object.assign({}, this.state, next);
  }
}
function setTimeout() { return 0; }
function setInterval() { return 0; }
function clearTimeout() {}
function clearInterval() {}
var console = { log() {}, warn() {}, error() {}, info() {}, debug() {} };
var window = new Proxy({}, {
  get(target, key) {
    if (key in target) return target[key];
    if (typeof key === 'string' && /^[A-Za-z]+_[0-9a-f]{6}$/.test(key)) {
      return new Proxy({}, { get(_, name) { return typeof name === 'string' ? { $component: key + '.' + name } : undefined; } });
    }
    return undefined;
  },
});
var document = {};
var React = {
  createElement(type, props) {
    const name = type && typeof type === 'object' && type.$component ? type.$component : type;
    return { $el: { type: name, props: props || {}, children: Array.prototype.slice.call(arguments, 2) } };
  },
};
window.React = React;
`

var errNoComponent = errors.New("the script has no Component class")

// runScript runs a canvas script and gives back the values of renderVals().
func runScript(src string, props map[string]any) (any, error) {
	vm := goja.New()
	timer := time.AfterFunc(scriptTimeout, func() {
		vm.Interrupt(fmt.Sprintf("the script ran for more than %s", scriptTimeout))
	})
	defer timer.Stop()

	if _, err := vm.RunString(prelude); err != nil {
		return nil, err
	}
	if err := vm.Set("__props", props); err != nil {
		return nil, err
	}
	v, err := vm.RunString("(function () {\n" + src + "\n;return typeof Component === 'function' ? new Component(__props) : null;\n})()")
	if err != nil {
		return nil, err
	}
	if goja.IsNull(v) || goja.IsUndefined(v) {
		return nil, errNoComponent
	}
	obj := v.ToObject(vm)
	render, ok := goja.AssertFunction(obj.Get("renderVals"))
	if !ok {
		return map[string]any{}, nil
	}
	vals, err := render(obj)
	if err != nil {
		return nil, err
	}

	return vals.Export(), nil
}

// scriptMessage is the first line of a script error, short enough for a marker.
func scriptMessage(err error) string {
	msg, _, _ := strings.Cut(err.Error(), "\n")
	if len(msg) > 200 {
		msg = msg[:200]
	}

	return msg
}
