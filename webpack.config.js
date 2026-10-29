const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
module.exports = {
  mode: 'development',
  entry: {
    'js/app': './src/js/app.js',
    'js/inicio': './src/js/inicio.js',
    'js/cotizaciones/index': './src/js/cotizaciones/index.js',
    'js/dashboard/index': './src/js/dashboard/index.js',
    'js/gastos/index': './src/js/gastos/index.js',
    'js/pintores/index': './src/js/pintores/index.js',
    'js/ventas/index': './src/js/ventas/index.js',
    'js/clientes/index': './src/js/clientes/index.js',
    'js/materiales/index': './src/js/materiales/index.js',
    'js/configuracion/index': './src/js/configuracion/index.js',
    'js/pedidos/index': './src/js/pedidos/index.js',
    'js/empaques/index': './src/js/empaques/index.js',
  },
  output: {
    filename: '[name].js',
    path: path.resolve(__dirname, 'public/build')
  },
  plugins: [
    new MiniCssExtractPlugin({
      filename: 'styles.css'
    })
  ],
  module: {
    rules: [
      {
        test: /\.(c|sc|sa)ss$/,
        use: [
          {
            loader: MiniCssExtractPlugin.loader
          },
          'css-loader',
          'sass-loader'
        ]
      },
      {
        test: /\.(png|svg|jpe?g|gif)$/,
        type: 'asset/resource',
      },
    ]
  }
};