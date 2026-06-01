import { ApiProperty } from '@nestjs/swagger';

export class PaymentAccountDto {
  @ApiProperty({ example: 1 })
  id!: number;

  @ApiProperty({ example: 1 })
  paymentMethodId!: number;

  @ApiProperty({ example: 'Cuenta Bancolombia' })
  alias!: string;

  @ApiProperty({ example: '123-456-789' })
  accountNumber!: string;

  @ApiProperty({ example: 'Ahorros' })
  accountType!: string;

  @ApiProperty({ example: 'Juan Pérez' })
  holderName!: string;

  @ApiProperty({ example: 'CC 123' })
  holderId!: string;

  @ApiProperty({ example: true })
  isActive!: boolean;
}

