import { ApiProperty } from '@nestjs/swagger';

export class AddOrderServiceDto {
  @ApiProperty()
  serviceId!: number;

  @ApiProperty({ example: 1 })
  quantity!: number;

  @ApiProperty({ example: 50000 })
  servicePrice!: number;
}

